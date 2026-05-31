<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Support;

use CarbonTrack\Support\Environment;
use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase
{
    public function testGetFallsBackFromEnvToServerAndProcessEnvironment(): void
    {
        $key = 'CARBONTRACK_ENVIRONMENT_TEST_VALUE';
        $previousEnvExists = array_key_exists($key, $_ENV);
        $previousEnv = $_ENV[$key] ?? null;
        $previousServerExists = array_key_exists($key, $_SERVER);
        $previousServer = $_SERVER[$key] ?? null;
        $previousProcess = getenv($key);

        try {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            $this->assertSame('fallback', Environment::string($key, 'fallback'));

            putenv($key . '=from_process');
            $this->assertSame('from_process', Environment::string($key));

            $_SERVER[$key] = 'from_server';
            $this->assertSame('from_server', Environment::string($key));

            $_ENV[$key] = 'from_env';
            $this->assertSame('from_env', Environment::string($key));
        } finally {
            if ($previousEnvExists) {
                $_ENV[$key] = $previousEnv;
            } else {
                unset($_ENV[$key]);
            }

            if ($previousServerExists) {
                $_SERVER[$key] = $previousServer;
            } else {
                unset($_SERVER[$key]);
            }

            if ($previousProcess !== false) {
                putenv($key . '=' . $previousProcess);
            } else {
                putenv($key);
            }
        }
    }
}
