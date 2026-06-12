<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Support;

use CarbonTrack\Support\ClientIpResolver;
use PHPUnit\Framework\TestCase;

class ClientIpResolverTest extends TestCase
{
    private ?string $previousTrustedCidrs = null;
    private ?string $previousTrustedCidrsEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTrustedCidrs = $_ENV['TRUSTED_PROXY_CIDRS'] ?? null;
        $envValue = getenv('TRUSTED_PROXY_CIDRS');
        $this->previousTrustedCidrsEnv = $envValue === false ? null : $envValue;
    }

    protected function tearDown(): void
    {
        if ($this->previousTrustedCidrs === null) {
            unset($_ENV['TRUSTED_PROXY_CIDRS']);
        } else {
            $_ENV['TRUSTED_PROXY_CIDRS'] = $this->previousTrustedCidrs;
        }

        if ($this->previousTrustedCidrsEnv === null) {
            putenv('TRUSTED_PROXY_CIDRS');
        } else {
            putenv('TRUSTED_PROXY_CIDRS=' . $this->previousTrustedCidrsEnv);
        }

        parent::tearDown();
    }

    public function testEmptyTrustedProxyConfigIgnoresCloudflareHeader(): void
    {
        unset($_ENV['TRUSTED_PROXY_CIDRS']);
        putenv('TRUSTED_PROXY_CIDRS');

        $ip = ClientIpResolver::fromServerParams([
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
        ]);

        $this->assertSame('198.51.100.10', $ip);
    }

    public function testTrustedCloudflareProxyPrefersCfConnectingIp(): void
    {
        $_ENV['TRUSTED_PROXY_CIDRS'] = '198.51.100.0/24';

        $ip = ClientIpResolver::fromServerParams([
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_CF_CONNECTING_IP' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.77',
        ]);

        $this->assertSame('203.0.113.9', $ip);
    }

    public function testTrustedProxyFallsBackToForwardedChainWhenCloudflareHeaderMissing(): void
    {
        $_ENV['TRUSTED_PROXY_CIDRS'] = '198.51.100.0/24';

        $ip = ClientIpResolver::fromServerParams([
            'REMOTE_ADDR' => '198.51.100.10',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.250, 203.0.113.77, 198.51.100.20',
        ]);

        $this->assertSame('203.0.113.77', $ip);
    }

    public function testTrustedProxySupportsIpv6Cidrs(): void
    {
        $_ENV['TRUSTED_PROXY_CIDRS'] = '2400:cb00::/32';

        $ip = ClientIpResolver::fromServerParams([
            'REMOTE_ADDR' => '2400:cb00::1234',
            'HTTP_CF_CONNECTING_IP' => '2001:db8::42',
        ]);

        $this->assertSame('2001:db8::42', $ip);
    }
}
