<?php

declare(strict_types=1);

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\PasskeyOperationException;
use CarbonTrack\Services\PasskeyService;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class PasskeyController
{
    public function __construct(
        private AuthService $authService,
        private PasskeyService $passkeyService,
        private Logger $logger,
        private ?ErrorLogService $errorLogService = null
    ) {
    }

    public function list(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED',
                ], 401);
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'passkeys' => $this->passkeyService->listForUser($user),
                ],
            ]);
        } catch (PasskeyOperationException $exception) {
            $this->logException($exception, $request, 'Passkey list operation failed');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->getErrorCode(),
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            $this->logException($exception, $request, 'Failed to list passkeys');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to list passkeys',
                'code' => 'PASSKEY_LIST_FAILED',
            ], 500);
        }
    }

    public function beginRegistration(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED',
                ], 401);
            }

            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $this->passkeyService->beginRegistration($user, $payload),
            ]);
        } catch (PasskeyOperationException $exception) {
            $this->logException($exception, $request, 'Passkey registration options operation failed');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->getErrorCode(),
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            $this->logException($exception, $request, 'Failed to create passkey registration options');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to create passkey registration options',
                'code' => 'PASSKEY_REGISTRATION_OPTIONS_FAILED',
            ], 500);
        }
    }

    public function completeRegistration(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED',
                ], 401);
            }

            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'passkey' => $this->passkeyService->completeRegistration($user, $payload),
                ],
            ], 201);
        } catch (PasskeyOperationException $exception) {
            $this->logException($exception, $request, 'Passkey registration verification operation failed');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->getErrorCode(),
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            $this->logException($exception, $request, 'Failed to complete passkey registration');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to complete passkey registration',
                'code' => 'PASSKEY_REGISTRATION_FAILED',
            ], 500);
        }
    }

    public function beginAuthentication(Request $request, Response $response): Response
    {
        try {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $this->passkeyService->beginAuthentication($payload),
            ]);
        } catch (PasskeyOperationException $exception) {
            $this->logException($exception, $request, 'Passkey authentication options operation failed');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->getErrorCode(),
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            $this->logException($exception, $request, 'Failed to create passkey authentication options');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to create passkey authentication options',
                'code' => 'PASSKEY_AUTHENTICATION_OPTIONS_FAILED',
            ], 500);
        }
    }

    public function completeAuthentication(Request $request, Response $response): Response
    {
        try {
            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];
            $result = $this->passkeyService->completeAuthentication($payload);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'token' => $this->authService->generateToken($result['user']),
                    'user' => $result['user'],
                    'passkey' => $result['passkey'],
                ],
            ]);
        } catch (PasskeyOperationException $exception) {
            $this->logException($exception, $request, 'Passkey authentication verification operation failed');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->getErrorCode(),
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            $this->logException($exception, $request, 'Failed to complete passkey authentication');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to complete passkey authentication',
                'code' => 'PASSKEY_AUTHENTICATION_FAILED',
            ], 500);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED',
                ], 401);
            }

            $passkeyId = isset($args['id']) ? (int) $args['id'] : 0;
            if ($passkeyId <= 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid passkey id',
                    'code' => 'INVALID_PASSKEY_ID',
                ], 400);
            }

            $this->passkeyService->deleteForUser($user, $passkeyId);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Passkey deleted successfully',
            ]);
        } catch (PasskeyOperationException $exception) {
            $this->logException($exception, $request, 'Passkey delete operation failed');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->getErrorCode(),
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            $this->logException($exception, $request, 'Failed to delete passkey');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to delete passkey',
                'code' => 'PASSKEY_DELETE_FAILED',
            ], 500);
        }
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Unauthorized',
                    'code' => 'UNAUTHORIZED',
                ], 401);
            }

            $passkeyId = isset($args['id']) ? (int) $args['id'] : 0;
            if ($passkeyId <= 0) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Invalid passkey id',
                    'code' => 'INVALID_PASSKEY_ID',
                ], 400);
            }

            $body = $request->getParsedBody();
            $payload = is_array($body) ? $body : [];
            $passkey = $this->passkeyService->updateLabelForUser(
                $user,
                $passkeyId,
                isset($payload['label']) ? (string) $payload['label'] : null
            );

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'passkey' => $passkey,
                ],
            ]);
        } catch (PasskeyOperationException $exception) {
            $this->logException($exception, $request, 'Passkey update operation failed');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => $exception->getMessage(),
                'code' => $exception->getErrorCode(),
            ], $exception->getHttpStatus());
        } catch (\Throwable $exception) {
            $this->logException($exception, $request, 'Failed to update passkey');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to update passkey',
                'code' => 'PASSKEY_UPDATE_FAILED',
            ], 500);
        }
    }

    public function adminList(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Access denied',
                    'code' => 'ACCESS_DENIED',
                ], 403);
            }

            $payload = $this->passkeyService->listForAdmin((int) $user['id'], $request->getQueryParams());

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $payload,
            ]);
        } catch (\Throwable $exception) {
            $this->logException($exception, $request, 'Failed to list admin passkeys');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to list admin passkeys',
                'code' => 'ADMIN_PASSKEY_LIST_FAILED',
            ], 500);
        }
    }

    public function adminStats(Request $request, Response $response): Response
    {
        try {
            $user = $this->authService->getCurrentUser($request);
            if (!$user || !$this->authService->isAdminUser($user)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Access denied',
                    'code' => 'ACCESS_DENIED',
                ], 403);
            }

            $stats = $this->passkeyService->getAdminStats((int) $user['id']);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Throwable $exception) {
            $this->logException($exception, $request, 'Failed to fetch admin passkey stats');
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Failed to fetch admin passkey stats',
                'code' => 'ADMIN_PASSKEY_STATS_FAILED',
            ], 500);
        }
    }

    private function logException(\Throwable $exception, Request $request, string $message): void
    {
        $this->logger->error($message, [
            'error' => $exception->getMessage(),
            'exception' => get_class($exception),
        ]);

        try {
            if ($this->errorLogService !== null) {
                $this->errorLogService->logException($exception, $request);
            }
        } catch (\Throwable $ignored) {
            $this->logger->error('PasskeyController failed to persist error log', [
                'error' => $ignored->getMessage(),
            ]);
        }
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
