<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\PasskeyConfig;
use PHPUnit\Framework\TestCase;

class PasskeyConfigTest extends TestCase
{
    public function testGetRpIdKeepsConfiguredSuffixWhenCompatibleWithFrontendHost(): void
    {
        $config = new PasskeyConfig([
            'PASSKEYS_RP_ID' => 'carbontrackapp.com',
            'FRONTEND_URL' => 'https://dev.carbontrackapp.com',
        ]);

        $this->assertSame('carbontrackapp.com', $config->getRpId());
    }

    public function testGetRpIdFallsBackToFrontendHostWhenConfiguredRpIdMismatchesFrontendHost(): void
    {
        $config = new PasskeyConfig([
            'PASSKEYS_RP_ID' => 'carbontrack.com',
            'PASSKEYS_ORIGINS' => 'https://dev.carbontrack.com',
            'FRONTEND_URL' => 'https://dev.carbontrackapp.com',
        ]);

        $this->assertSame('dev.carbontrackapp.com', $config->getRpId());
    }

    public function testGetAllowedOriginsIncludesFrontendOriginWhenExplicitOriginsMissIt(): void
    {
        $config = new PasskeyConfig([
            'PASSKEYS_ORIGINS' => 'https://dev.carbontrack.com/',
            'FRONTEND_URL' => 'https://dev.carbontrackapp.com/',
        ]);

        $this->assertSame([
            'https://dev.carbontrack.com',
            'https://dev.carbontrackapp.com',
        ], $config->getAllowedOrigins());
    }

    public function testGetAllowedOriginsFallsBackToFrontendOriginBeforeAppUrl(): void
    {
        $config = new PasskeyConfig([
            'FRONTEND_URL' => 'https://dev.carbontrackapp.com/path',
            'APP_URL' => 'https://dev-api.carbontrackapp.com',
        ]);

        $this->assertSame([
            'https://dev.carbontrackapp.com',
        ], $config->getAllowedOrigins());
    }

    public function testGetAllowedOriginsFallsBackToAppUrlWhenFrontendUrlMissing(): void
    {
        $config = new PasskeyConfig([
            'APP_URL' => 'https://dev-api.carbontrackapp.com/api',
        ]);

        $this->assertSame([
            'https://dev-api.carbontrackapp.com',
        ], $config->getAllowedOrigins());
    }
}
