<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Support\SyntheticRequestFactory;
use CarbonTrack\Support\Environment;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use Monolog\Logger;

class TurnstileService
{
    private string $secretKey;
    private Logger $logger;
    private string $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    private ?AuditLogService $auditLogService;
    private ?ErrorLogService $errorLogService;
    private ?string $caBundlePath;
    private bool $useNativeCaStore;
    private ?ClientInterface $httpClient;

    public function __construct(
        string $secretKey,
        Logger $logger,
        ?AuditLogService $auditLogService = null,
        ?ErrorLogService $errorLogService = null,
        ?string $caBundlePath = null,
        bool $useNativeCaStore = false,
        ?ClientInterface $httpClient = null
    )
    {
        $this->secretKey = $secretKey;
        $this->logger = $logger;
        $this->auditLogService = $auditLogService;
        $this->errorLogService = $errorLogService;
        $this->caBundlePath = is_string($caBundlePath) && trim($caBundlePath) !== '' ? trim($caBundlePath) : null;
        $this->useNativeCaStore = $useNativeCaStore;
        $this->httpClient = $httpClient;
    }

    /**
     * Verify Turnstile token
     *
     * @param string $token The Turnstile token from the client
     * @param string|null $remoteIp The client's IP address
     * @return array Verification result with success status and details
     */
    public function verify(string $token, ?string $remoteIp = null): array
    {
        $appEnv = strtolower(Environment::string('APP_ENV'));
        // Production never honours bypass flags. Non-production must opt in explicitly
        // through ALLOW_TURNSTILE_BYPASS (B-104). The legacy TURNSTILE_BYPASS flag is
        // kept as a synonym for backward compatibility with existing deployments.
        $bypassRequested = filter_var(
            Environment::get('ALLOW_TURNSTILE_BYPASS', Environment::get('TURNSTILE_BYPASS', false)),
            FILTER_VALIDATE_BOOLEAN
        );
        if ($appEnv !== 'production' && $bypassRequested) {
            return ['success' => true, 'bypassed' => true];
        }

        // In production, refuse to validate against an unconfigured / placeholder
        // secret rather than silently failing-open or hammering Cloudflare.
        if ($appEnv === 'production') {
            $trimmedSecret = trim($this->secretKey);
            if ($trimmedSecret === '' || $trimmedSecret === 'your-turnstile-secret-key') {
                $this->logAudit('turnstile_secret_unconfigured', ['remote_ip' => $remoteIp], 'failed');
                $this->logFailure(
                    'turnstile_secret_unconfigured',
                    new \RuntimeException('TURNSTILE_SECRET_KEY missing or set to placeholder'),
                    ['remote_ip' => $remoteIp],
                    '/internal/turnstile/verify'
                );
                return [
                    'success' => false,
                    'error' => 'secret_unconfigured',
                    'message' => 'Turnstile secret key is not configured',
                ];
            }
        }

        if (empty($token)) {
            $this->logAudit('turnstile_verification_missing_token', ['remote_ip' => $remoteIp], 'failed');
            return [
                'success' => false,
                'error' => 'missing-input-response',
                'message' => 'Turnstile token is required'
            ];
        }

        $postData = [
            'secret' => $this->secretKey,
            'response' => $token
        ];

        if ($remoteIp) {
            $postData['remoteip'] = $remoteIp;
        }

        try {
            $client = $this->httpClient ??= $this->createHttpClient();
            $verificationResponse = $client->request('POST', $this->verifyUrl, $this->verificationOptions($postData));
            $httpCode = $verificationResponse->getStatusCode();
            $response = (string) $verificationResponse->getBody();

            if ($httpCode !== 200) {
                $this->logFailure('turnstile_verification_http_failed', new \RuntimeException('Unexpected HTTP status: ' . $httpCode), [
                    'http_code' => $httpCode,
                    'remote_ip' => $remoteIp,
                ], '/internal/turnstile/verify');
                $this->logger->error('Turnstile verification HTTP error', [
                    'http_code' => $httpCode,
                    'response' => $response,
                    'token' => substr($token, 0, 20) . '...',
                    'ip' => $remoteIp
                ]);

                return [
                    'success' => false,
                    'error' => 'http-error',
                    'message' => 'Turnstile verification service returned error'
                ];
            }

            $result = json_decode($response, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logFailure('turnstile_verification_decode_failed', new \RuntimeException(json_last_error_msg()), ['remote_ip' => $remoteIp], '/internal/turnstile/verify');
                $this->logger->error('Turnstile verification JSON decode error', [
                    'json_error' => json_last_error_msg(),
                    'response' => $response,
                    'token' => substr($token, 0, 20) . '...',
                    'ip' => $remoteIp
                ]);

                return [
                    'success' => false,
                    'error' => 'invalid-response',
                    'message' => 'Invalid response from Turnstile verification service'
                ];
            }

            if ($result['success']) {
                $this->logAudit('turnstile_verification_succeeded', [
                    'remote_ip' => $remoteIp,
                    'hostname' => $result['hostname'] ?? null,
                ]);
                $this->logger->info('Turnstile verification successful', [
                    'token' => substr($token, 0, 20) . '...',
                    'ip' => $remoteIp,
                    'challenge_ts' => $result['challenge_ts'] ?? null,
                    'hostname' => $result['hostname'] ?? null
                ]);

                return [
                    'success' => true,
                    'challenge_ts' => $result['challenge_ts'] ?? null,
                    'hostname' => $result['hostname'] ?? null,
                    'action' => $result['action'] ?? null,
                    'cdata' => $result['cdata'] ?? null
                ];
            } else {
                $errorCodes = $result['error-codes'] ?? ['unknown-error'];
                $this->logAudit('turnstile_verification_failed', [
                    'remote_ip' => $remoteIp,
                    'error_codes' => $errorCodes,
                ], 'failed');
                $this->logger->warning('Turnstile verification failed', [
                    'error_codes' => $errorCodes,
                    'token' => substr($token, 0, 20) . '...',
                    'ip' => $remoteIp
                ]);

                return [
                    'success' => false,
                    'error' => $errorCodes[0],
                    'error_codes' => $errorCodes,
                    'message' => $this->getErrorMessage($errorCodes[0])
                ];
            }

        } catch (TransferException $error) {
            $this->logFailure('turnstile_verification_network_failed', $error, ['remote_ip' => $remoteIp], '/internal/turnstile/verify');
            $this->logger->error('Turnstile verification network error', [
                'error' => $error->getMessage(),
                'ip' => $remoteIp,
            ]);

            return [
                'success' => false,
                'error' => 'network-error',
                'message' => 'Failed to connect to Turnstile verification service',
            ];
        } catch (\Throwable $e) {
            $this->logFailure('turnstile_verification_exception', $e, ['remote_ip' => $remoteIp], '/internal/turnstile/verify');
            $this->logger->error('Turnstile verification exception', [
                'error' => $e->getMessage(),
                'token' => substr($token, 0, 20) . '...',
                'ip' => $remoteIp
            ]);

            return [
                'success' => false,
                'error' => 'internal-error',
                'message' => 'Internal error during Turnstile verification'
            ];
        }
    }

    /**
     * Get human-readable error message for Turnstile error codes
     */
    private function getErrorMessage(string $errorCode): string
    {
        $errorMessages = [
            'missing-input-secret' => 'The secret parameter is missing',
            'invalid-input-secret' => 'The secret parameter is invalid or malformed',
            'missing-input-response' => 'The response parameter is missing',
            'invalid-input-response' => 'The response parameter is invalid or malformed',
            'bad-request' => 'The request is invalid or malformed',
            'timeout-or-duplicate' => 'The response is no longer valid: either is too old or has been used previously',
            'internal-error' => 'An internal error happened while validating the response',
            'unknown-error' => 'Unknown error occurred during verification'
        ];

        return $errorMessages[$errorCode] ?? 'Unknown error occurred during verification';
    }

    /**
     * Validate that Turnstile is properly configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->secretKey);
    }

    private function logAudit(string $action, array $context = [], string $status = 'success'): void
    {
        if ($this->auditLogService === null) {
            return;
        }

        try {
            $this->auditLogService->log([
                'action' => $action,
                'operation_category' => 'security',
                'actor_type' => 'system',
                'status' => $status,
                'data' => $context,
            ]);
        } catch (\Throwable $ignore) {
            // ignore audit failures for turnstile service
        }
    }

    private function logFailure(string $action, \Throwable $e, array $context, string $path): void
    {
        $this->logAudit($action, $context, 'failed');

        if ($this->errorLogService === null) {
            return;
        }

        try {
            $request = SyntheticRequestFactory::fromContext($path, 'POST', null, [], $context);
            $this->errorLogService->logException($e, $request, ['context_message' => $action] + $context);
        } catch (\Throwable $ignore) {
            // ignore error log failures for turnstile service
        }
    }

    private function createHttpClient(): ClientInterface
    {
        if (\function_exists('curl_init')) {
            return new Client();
        }

        if (!\ini_get('allow_url_fopen') || !\in_array('https', \stream_get_wrappers(), true)) {
            throw new \RuntimeException('Turnstile verification needs PHP cURL or HTTPS streams (OpenSSL with allow_url_fopen enabled).');
        }

        // Select the stream handler even when cURL is loaded but curl_init is disabled.
        return new Client(['handler' => HandlerStack::create(new StreamHandler())]);
    }

    private function verificationOptions(array $postData): array
    {
        $options = [
            'form_params' => $postData,
            'headers' => ['User-Agent' => 'CarbonTrack/1.0'],
            'timeout' => 10,
            'connect_timeout' => 5,
            'read_timeout' => 10,
            'verify' => $this->caBundlePath ?? true,
            'allow_redirects' => false,
            'http_errors' => false,
        ];

        if (
            $this->useNativeCaStore
            && \function_exists('curl_init')
            && \defined('CURLOPT_SSL_OPTIONS')
            && \defined('CURLSSLOPT_NATIVE_CA')
        ) {
            $options['curl'] = [CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA];
        }

        return $options;
    }
}

