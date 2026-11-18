<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

class PasskeyIntegrationUnavailableException extends PasskeyOperationException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'WebAuthn verification requires the PHP OpenSSL extension.',
            'WEBAUTHN_LIBRARY_UNAVAILABLE',
            501,
            $previous
        );
    }
}
