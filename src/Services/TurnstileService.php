<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Support\SyntheticRequestFactory;
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

    public function __construct(
        string $secretKey,
        Logger $logger,
        ?AuditLogService $auditLogService = null,
        ?ErrorLogService $errorLogService = null,
        ?string $caBundlePath = null,
        bool $useNativeCaStore = false
    )
    {
        $this->secretKey = $secretKey;
        $this->logger = $logger;
        $this->auditLogService = $auditLogService;
        $this->errorLogService = $errorLogService;
        $this->caBundlePath = is_string($caBundlePath) && trim($caBundlePath) !== '' ? trim($caBundlePath) : null;
        $this->useNativeCaStore = $useNativeCaStore;
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
        $appEnv = strtolower((string)($_ENV['APP_ENV'] ?? ''));
        $bypass = filter_var($_ENV['TURNSTILE_BYPASS'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($appEnv === 'testing' || $bypass) {
            return ['success' => true, 'bypassed' => true];
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
            $ch = curl_init();
            $curlOptions = [
                CURLOPT_URL => $this->verifyUrl,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query($postData),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'CarbonTrack/1.0',
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded'
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2
            ];

            $this->applyCertificateOptions($curlOptions);
            curl_setopt_array($ch, $curlOptions);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
                $this->logFailure('turnstile_verification_network_failed', new \RuntimeException($curlError), ['remote_ip' => $remoteIp], '/internal/turnstile/verify');
                $this->logger->error('Turnstile verification cURL error', [
                    'error' => $curlError,
                    'token' => substr($token, 0, 20) . '...',
                    'ip' => $remoteIp
                ]);

                return [
                    'success' => false,
                    'error' => 'network-error',
                    'message' => 'Failed to connect to Turnstile verification service'
                ];
            }

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

        } catch (\Exception $e) {
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

    /**
     * @param array<int|string, mixed> $curlOptions
     */
    private function applyCertificateOptions(array &$curlOptions): void
    {
        if ($this->caBundlePath !== null) {
            $curlOptions[CURLOPT_CAINFO] = $this->caBundlePath;
        }

        if (
            $this->useNativeCaStore
            && \defined('CURLOPT_SSL_OPTIONS')
            && \defined('CURLSSLOPT_NATIVE_CA')
        ) {
            $existingSslOptions = $curlOptions[CURLOPT_SSL_OPTIONS] ?? 0;
            $curlOptions[CURLOPT_SSL_OPTIONS] = $existingSslOptions | CURLSSLOPT_NATIVE_CA;
        }
    }
}

