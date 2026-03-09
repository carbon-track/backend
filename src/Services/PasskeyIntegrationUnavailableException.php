<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

class PasskeyIntegrationUnavailableException extends PasskeyOperationException
{
    public function __construct(string $packageName, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                'WebAuthn verification is not available because %s is not installed in this environment.',
                $packageName
            ),
            'WEBAUTHN_LIBRARY_UNAVAILABLE',
            501,
            $previous
        );
    }
}
