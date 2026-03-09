<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

class NullWebauthnProvider implements WebauthnProviderInterface
{
    private const EXPECTED_CLASS = 'Webauthn\\PublicKeyCredentialCreationOptions';

    public function __construct(private PasskeyConfig $config)
    {
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getMetadata(): array
    {
        return [
            'package' => $this->config->getPreferredLibraryPackage(),
            'available' => false,
            'expected_class' => self::EXPECTED_CLASS,
            'reason' => class_exists(self::EXPECTED_CLASS) ? 'provider_not_implemented' : 'package_not_installed',
        ];
    }

    public function verifyRegistrationResponse(
        array $credential,
        array $challengeRecord,
        array $user,
        PasskeyConfig $config
    ): array {
        unset($credential, $challengeRecord, $user, $config);

        throw new PasskeyIntegrationUnavailableException($this->config->getPreferredLibraryPackage());
    }

    public function verifyAuthenticationResponse(
        array $credential,
        array $challengeRecord,
        array $passkey,
        PasskeyConfig $config
    ): array {
        unset($credential, $challengeRecord, $passkey, $config);

        throw new PasskeyIntegrationUnavailableException($this->config->getPreferredLibraryPackage());
    }
}
