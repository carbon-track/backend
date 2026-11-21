<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\LogDiagnostics\DiagnosticAccess;
use CarbonTrack\Services\LogDiagnostics\DiagnosticConfig;
use CarbonTrack\Services\LogDiagnostics\DiagnosticOAuth;
use CarbonTrack\Services\LogDiagnostics\DiagnosticProblem;
use CarbonTrack\Services\LogDiagnostics\DiagnosticQueries;
use CarbonTrack\Support\LogDiagnosticRequest;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class LogDiagnosticController
{
    public function __construct(
        private readonly DiagnosticConfig $config,
        private readonly DiagnosticAccess $access,
        private readonly DiagnosticQueries $queries,
        private readonly DiagnosticOAuth $oauth,
        private readonly ErrorLogService $errorLogService
    ) {
    }

    public function settings(Request $request, Response $response): Response
    {
        return $this->guard($request, $response, function () use ($request, $response): Response {
            if (!$this->config->enabled) {
                return $this->json($response, ['enabled' => false]);
            }
            $adminId = $this->access->adminId($request);
            $this->access->record($request, 'settings_viewed', null, [], $adminId);
            return $this->json($response, [
                'enabled' => true,
                'rest_url' => $this->config->apiOrigin . DiagnosticConfig::API,
                'mcp_url' => $this->config->resource(),
                'max_session_minutes' => $this->config->maxSessionMinutes,
            ]);
        });
    }

    public function sessions(Request $request, Response $response): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json($response, $this->access->list(
            $request, $this->before($request), ($request->getQueryParams()['eligible'] ?? null) === '1'
        )));
    }

    public function create(Request $request, Response $response): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json(
            $response, $this->access->create($request, $this->body($request)), 201
        ));
    }

    public function detail(Request $request, Response $response, array $args): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json(
            $response, $this->access->detail($request, (int) $args['id'])
        ));
    }

    public function history(Request $request, Response $response, array $args): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json(
            $response, $this->access->history($request, (int) $args['id'], $this->before($request))
        ));
    }

    public function issue(Request $request, Response $response, array $args): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json(
            $response, $this->access->issueRest($request, (int) $args['id']), 201
        ));
    }

    public function revoke(Request $request, Response $response, array $args): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json(
            $response,
            $this->access->revoke($request, (int) $args['id'], isset($args['credential_id']) ? (int) $args['credential_id'] : null)
        ));
    }

    public function summary(Request $request, Response $response): Response
    {
        return $this->query($request, $response, 'get_log_summary');
    }

    public function search(Request $request, Response $response): Response
    {
        return $this->query($request, $response, 'search_logs');
    }

    public function context(Request $request, Response $response): Response
    {
        return $this->query($request, $response, 'get_trace_context');
    }

    public function resourceMetadata(Request $request, Response $response): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json($response, $this->oauth->resourceMetadata()));
    }

    public function issuerMetadata(Request $request, Response $response): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json($response, $this->oauth->issuerMetadata()));
    }

    public function authorize(Request $request, Response $response): Response
    {
        return $this->guard($request, $response, function () use ($request, $response): Response {
            $result = $this->oauth->begin($request);
            // begin() selects the configured admin origin or a validated client callback.
            $response = $response->withStatus(303)
                ->withHeader('Location', $result['redirect'])
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Referrer-Policy', 'no-referrer');
            if (isset($result['browser'])) {
                $response = $response->withHeader(
                    'Set-Cookie',
                    DiagnosticOAuth::BROWSER_COOKIE . '=' . $result['browser'] . '; Path=/; Max-Age='
                    . DiagnosticOAuth::REQUEST_SECONDS . '; Secure; HttpOnly; SameSite=Lax'
                );
            }
            return $response;
        });
    }

    public function oauthRequest(Request $request, Response $response, array $args): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json(
            $response, $this->oauth->describe($request, $args['id'])
        ));
    }

    public function decision(Request $request, Response $response, array $args): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json(
            $response, $this->oauth->decide($request, $args['id'], $this->body($request))
        ));
    }

    public function consent(Request $request, Response $response): Response
    {
        return $this->guard($request, $response, function () use ($request, $response): Response {
            $this->config->ensureEnabled();
            if ($request->getMethod() === 'POST') {
                return $this->json($response, [
                    'callback_url' => $this->oauth->confirm($request, $this->body($request, true)['ticket'] ?? null),
                ]);
            }
            $nonce = DiagnosticConfig::secret();
            $language = preg_match('/^\s*zh(?:[-;,]|$)/i', $request->getHeaderLine('Accept-Language')) ? 'zh' : 'en';
            ob_start();
            try {
                require __DIR__ . '/../../templates/log-diagnostics/consent.php';
            } finally {
                $html = ob_get_clean();
            }
            $response->getBody()->write($html);
            return $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withHeader('Content-Language', $language)
                ->withHeader('Cache-Control', 'no-store')
                ->withHeader('Referrer-Policy', 'no-referrer')
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Content-Security-Policy', "default-src 'none'; script-src 'nonce-$nonce'; style-src 'nonce-$nonce'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
        });
    }

    public function token(Request $request, Response $response): Response
    {
        return $this->guard($request, $response, fn (): Response => $this->json(
            $response, $this->oauth->exchange($request, $this->body($request, true))
        )->withHeader('Pragma', 'no-cache'), oauthToken: true);
    }

    private function query(Request $request, Response $response, string $tool): Response
    {
        return $this->guard($request, $response, function () use ($request, $response, $tool): Response {
            $input = $this->body($request);
            try {
                $data = $this->access->search($request, 'rest', $tool, $input,
                    fn (object $grant, int $deadline): array => $this->queries->find($tool, $grant, $input, $deadline));
            } catch (DiagnosticProblem $problem) { // nosemgrep: semgrep.enforce-errorlogservice-in-catch
                // DiagnosticAccess already logs and audits failures while diagnostics is enabled.
                return $this->problem($request, $response, $problem, false);
            }
            return $this->json($response, $data);
        });
    }

    private function guard(Request $request, Response $response, callable $operation, bool $oauthToken = false): Response
    {
        try {
            return $operation();
        } catch (\Throwable $failure) {
            $error = $failure instanceof DiagnosticProblem ? $failure : DiagnosticProblem::unavailable();
            if ($this->config->enabled) {
                $userId = (int) $request->getAttribute('user_id');
                $details = $error->logDetails();
                try {
                    $this->access->record($request, 'rejected', null, $details,
                        $userId > 0 ? $userId : null, $error->status);
                } catch (\Throwable) {
                    // A failed audit can never make a protected result available.
                }
                $this->errorLogService->logError('LogDiagnosticFailure', $error->reason, $request, [
                    'diagnostic' => $details,
                ]);
            }
            return $this->problem($request, $response, $error, $oauthToken);
        }
    }

    private function problem(Request $request, Response $response, DiagnosticProblem $error, bool $oauthToken): Response
    {
        if ($oauthToken) {
            if ($error->status === 401 && str_starts_with(strtolower($request->getHeaderLine('Authorization')), 'basic ')) {
                $response = $response->withHeader('WWW-Authenticate', 'Basic realm="CarbonTrack log diagnostics"');
            }
            return $this->json($response->withHeader('Pragma', 'no-cache'), [
                'error' => $error->status >= 500 ? 'server_error' : $error->reason,
                'error_description' => $error->getMessage(),
            ], $error->status);
        }
        if ($error->status === 401) {
            $response = $response->withHeader('WWW-Authenticate', 'Bearer error="invalid_token"');
        }
        if ($error->retryAfter !== null) {
            $response = $response->withHeader('Retry-After', (string) $error->retryAfter);
        }
        return $this->json($response, [
            'error' => $error->reason, 'message' => $error->getMessage(), 'request_id' => LogDiagnosticRequest::requestId($request),
        ], $error->status);
    }

    private function body(Request $request, bool $form = false): array
    {
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'), 2)[0]));
        $body = $request->getParsedBody();
        $expected = $form ? 'application/x-www-form-urlencoded' : 'application/json';
        if ($contentType !== $expected || !is_array($body) || ($body !== [] && array_is_list($body))) {
            throw DiagnosticProblem::invalid($form ? 'Expected an OAuth form body.' : 'Expected a JSON object.');
        }
        return $body;
    }

    private function before(Request $request): ?int
    {
        $value = $request->getQueryParams()['before_id'] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || !ctype_digit($value) || (int) $value < 1 || (int) $value > 2147483647) {
            throw DiagnosticProblem::invalid('Invalid before_id.');
        }
        return (int) $value;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
