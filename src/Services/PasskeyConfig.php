<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

class PasskeyConfig
{
    private const DEFAULT_ALLOWED_ALGORITHMS = [-7, -257];
    private const DEFAULT_TRANSPORTS = ['internal', 'hybrid', 'usb', 'ble', 'nfc'];

    /**
     * @param array<string, mixed>|null $env
     */
    public function __construct(private ?array $env = null)
    {
        $this->env = $env ?? $_ENV;
    }

    public function isEnabled(): bool
    {
        return $this->getBool('PASSKEYS_ENABLED', true);
    }

    public function getRpId(): string
    {
        $value = trim((string) ($this->env['PASSKEYS_RP_ID'] ?? ''));
        if ($value !== '') {
            return strtolower($value);
        }

        $urlHost = $this->resolveHostFromUrl((string) ($this->env['FRONTEND_URL'] ?? ''));
        if ($urlHost !== null) {
            return $urlHost;
        }

        $appUrlHost = $this->resolveHostFromUrl((string) ($this->env['APP_URL'] ?? ''));
        if ($appUrlHost !== null) {
            return $appUrlHost;
        }

        return 'localhost';
    }

    public function getRpName(): string
    {
        $value = trim((string) ($this->env['PASSKEYS_RP_NAME'] ?? ''));
        if ($value !== '') {
            return $value;
        }

        $appName = trim((string) ($this->env['APP_NAME'] ?? ''));
        return $appName !== '' ? $appName : 'CarbonTrack';
    }

    /**
     * @return string[]
     */
    public function getAllowedOrigins(): array
    {
        $origins = $this->splitCsv((string) ($this->env['PASSKEYS_ORIGINS'] ?? ''));
        if ($origins !== []) {
            return $origins;
        }

        $fallbacks = [];
        foreach (['FRONTEND_URL', 'APP_URL'] as $key) {
            $value = trim((string) ($this->env[$key] ?? ''));
            if ($value !== '') {
                $fallbacks[] = $value;
            }
        }

        return array_values(array_unique($fallbacks));
    }

    public function getChallengeTtlSeconds(): int
    {
        return max(60, $this->getInt('PASSKEYS_CHALLENGE_TTL_SECONDS', 300));
    }

    public function getRegistrationTimeoutMs(): int
    {
        return max(60000, $this->getInt('PASSKEYS_REGISTRATION_TIMEOUT_MS', $this->getChallengeTtlSeconds() * 1000));
    }

    public function getAuthenticationTimeoutMs(): int
    {
        return max(30000, $this->getInt('PASSKEYS_AUTHENTICATION_TIMEOUT_MS', $this->getChallengeTtlSeconds() * 1000));
    }

    public function getAttestationPreference(): string
    {
        $value = trim((string) ($this->env['PASSKEYS_ATTESTATION'] ?? 'none'));
        return $value !== '' ? $value : 'none';
    }

    public function getResidentKeyPreference(): string
    {
        $value = trim((string) ($this->env['PASSKEYS_RESIDENT_KEY'] ?? 'preferred'));
        return $value !== '' ? $value : 'preferred';
    }

    public function getUserVerificationPreference(): string
    {
        $value = trim((string) ($this->env['PASSKEYS_USER_VERIFICATION'] ?? 'preferred'));
        return $value !== '' ? $value : 'preferred';
    }

    public function getAuthenticatorAttachment(): ?string
    {
        $value = trim((string) ($this->env['PASSKEYS_AUTHENTICATOR_ATTACHMENT'] ?? ''));
        return $value !== '' ? $value : null;
    }

    /**
     * @return int[]
     */
    public function getAllowedAlgorithms(): array
    {
        $values = $this->splitCsv((string) ($this->env['PASSKEYS_ALLOWED_ALGORITHMS'] ?? ''));
        if ($values === []) {
            return self::DEFAULT_ALLOWED_ALGORITHMS;
        }

        $algorithms = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $algorithms[] = (int) $value;
            }
        }

        return $algorithms !== [] ? array_values(array_unique($algorithms)) : self::DEFAULT_ALLOWED_ALGORITHMS;
    }

    /**
     * @return string[]
     */
    public function getDefaultTransports(): array
    {
        $values = $this->splitCsv((string) ($this->env['PASSKEYS_DEFAULT_TRANSPORTS'] ?? ''));
        return $values !== [] ? $values : self::DEFAULT_TRANSPORTS;
    }

    public function getPreferredLibraryPackage(): string
    {
        return 'web-auth/webauthn-lib';
    }

    private function getBool(string $key, bool $default): bool
    {
        if (!array_key_exists($key, $this->env)) {
            return $default;
        }

        $value = filter_var($this->env[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $value ?? $default;
    }

    private function getInt(string $key, int $default): int
    {
        if (!array_key_exists($key, $this->env)) {
            return $default;
        }

        $value = filter_var($this->env[$key], FILTER_VALIDATE_INT);
        return $value === false ? $default : (int) $value;
    }

    /**
     * @return string[]
     */
    private function splitCsv(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));
        $parts = array_filter($parts, static fn (string $item): bool => $item !== '');
        return array_values(array_unique($parts));
    }

    private function resolveHostFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }
}
