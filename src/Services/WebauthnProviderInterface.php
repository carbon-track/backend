<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

interface WebauthnProviderInterface
{
    public function isAvailable(): bool;

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array;

    /**
     * @param array<string, mixed> $credential
     * @param array<string, mixed> $challengeRecord
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function verifyRegistrationResponse(
        array $credential,
        array $challengeRecord,
        array $user,
        PasskeyConfig $config
    ): array;

    /**
     * @param array<string, mixed> $credential
     * @param array<string, mixed> $challengeRecord
     * @param array<string, mixed> $passkey
     * @return array<string, mixed>
     */
    public function verifyAuthenticationResponse(
        array $credential,
        array $challengeRecord,
        array $passkey,
        PasskeyConfig $config
    ): array;
}
