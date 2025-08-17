<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use Monolog\Logger;

class TurnstileService
{
    private string $secretKey;
    private Logger $logger;
    private string $verifyUrl = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(string $secretKey, Logger $logger)
    {
        $this->secretKey = $secretKey;
        $this->logger = $logger;
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
        if (empty($token)) {
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
            curl_setopt_array($ch, [
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
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError) {
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
}

