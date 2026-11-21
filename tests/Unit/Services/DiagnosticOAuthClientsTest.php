<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Services\LogDiagnostics\DiagnosticConfig;
use CarbonTrack\Services\LogDiagnostics\DiagnosticOAuthClients;
use CarbonTrack\Services\LogDiagnostics\DiagnosticProblem;
use DateTimeZone;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DiagnosticOAuthClientsTest extends TestCase
{
    private const CLIENT_ID = 'https://1.1.1.1/client.json';
    private const JWKS_URI = 'https://1.1.1.1/jwks.json';
    private const REDIRECT = 'https://connector.diagnostics.test/callback';

    public function testCimdUsesPinnedClientIdentityRedirectAndMethods(): void
    {
        $document = [
            'client_id' => self::CLIENT_ID,
            'client_name' => 'Fixture connector',
            'redirect_uris' => [self::REDIRECT, 'https://other.diagnostics.test/callback'],
            'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
            'token_endpoint_auth_method' => 'private_key_jwt',
            'jwks_uri' => self::JWKS_URI,
        ];
        [$clients, $http] = $this->clients($document);
        $client = $clients->registered(self::CLIENT_ID);
        self::assertTrue($clients->supportsCimd());
        self::assertSame(['none', 'private_key_jwt'], $clients->methods());
        self::assertSame(self::REDIRECT, $clients->validateRedirect($client, self::REDIRECT, true));
        self::assertSame(self::CLIENT_ID, (string) $http->getLastRequest()->getUri());

        $request = (new ServerRequestFactory())->createServerRequest('POST', 'https://diagnostics.test/token');
        self::assertNull($clients->authenticate($client, $request, ['client_id' => self::CLIENT_ID]));

        $this->expectException(DiagnosticProblem::class);
        $this->expectExceptionMessage('The OAuth redirect URI is invalid.');
        $clients->validateRedirect($client, 'https://other.diagnostics.test/callback', true);
    }

    public function testCimdRejectsDocumentForAnotherClient(): void
    {
        [$clients] = $this->clients([
            'client_id' => 'https://other.diagnostics.test/client.json',
            'client_name' => 'Other',
            'redirect_uris' => [self::REDIRECT],
            'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
            'jwks_uri' => self::JWKS_URI,
        ]);
        $this->expectException(DiagnosticProblem::class);
        $clients->validateRedirect($clients->registered(self::CLIENT_ID), self::REDIRECT, true);
    }

    public function testCimdAndRegistrationAcceptTheRuntimeLoopbackPort(): void
    {
        $redirects = ['http://127.0.0.1/callback', 'http://[::1]/callback?flow=agent'];
        $document = [
            'client_id' => self::CLIENT_ID,
            'client_name' => 'Fixture native client',
            'redirect_uris' => $redirects,
            'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
            'jwks_uri' => self::JWKS_URI,
        ];
        [$clients] = $this->clients($document, $document, $document);
        $client = array_replace($clients->registered(self::CLIENT_ID), ['redirect_uris' => $redirects]);
        foreach ([
            'http://127.0.0.1:30471/callback',
            'http://127.0.0.1:17643/callback',
            'http://[::1]:65535/callback?flow=agent',
        ] as $uri) {
            self::assertSame($uri, $clients->validateRedirect($client, $uri, true));
        }
    }

    /** @dataProvider invalidLoopbackRedirects */
    public function testDynamicLoopbackMatchingPreservesTheRegisteredUri(string $registered, string $requested): void
    {
        [$clients] = $this->clients();
        $client = array_replace($clients->registered(self::CLIENT_ID), ['redirect_uris' => [$registered]]);
        $this->expectException(DiagnosticProblem::class);
        $this->expectExceptionMessage('The OAuth redirect URI is invalid.');
        $clients->validateRedirect($client, $requested, false);
    }

    public static function invalidLoopbackRedirects(): array
    {
        return [
            'path' => ['http://127.0.0.1/callback', 'http://127.0.0.1:17643/other'],
            'query' => ['http://127.0.0.1/callback?flow=agent', 'http://127.0.0.1:17643/callback?flow=other'],
            'host' => ['http://127.0.0.1/callback', 'http://localhost:17643/callback'],
            'userinfo' => ['http://127.0.0.1/callback', 'http://127.0.0.1:17643@agent.diagnostics.test/callback'],
            'fixed port' => ['http://127.0.0.1:17643/callback', 'http://127.0.0.1:30471/callback'],
            'zero port' => ['http://127.0.0.1/callback', 'http://127.0.0.1:0/callback'],
            'out of range port' => ['http://127.0.0.1/callback', 'http://127.0.0.1:65536/callback'],
            'remote HTTPS' => [self::REDIRECT, 'https://connector.diagnostics.test:8443/callback'],
        ];
    }

    public function testCimdStillRequiresItsOwnMatchingLoopbackRegistration(): void
    {
        [$clients] = $this->clients([
            'client_id' => self::CLIENT_ID,
            'client_name' => 'Fixture native client',
            'redirect_uris' => ['http://127.0.0.1:17643/callback'],
            'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
            'jwks_uri' => self::JWKS_URI,
        ]);
        $client = array_replace($clients->registered(self::CLIENT_ID), [
            'redirect_uris' => ['http://127.0.0.1/callback'],
        ]);
        $this->expectException(DiagnosticProblem::class);
        $this->expectExceptionMessage('OAuth client validation failed.');
        $clients->validateRedirect($client, 'http://127.0.0.1:30471/callback', true);
    }

    public function testPreRegisteredCimdUsesLocalMetadataForAuthorizationAndExchange(): void
    {
        [$clients, $http] = $this->clients();
        $loopback = 'http://127.0.0.1/callback';
        $client = array_replace($clients->registered(self::CLIENT_ID), [
            'redirect_uris' => [$loopback, self::REDIRECT],
            'client_metadata_document' => [
                'client_id' => self::CLIENT_ID,
                'client_name' => 'Fixture native client',
                'redirect_uris' => [$loopback],
                'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'],
                'jwks_uri' => self::JWKS_URI,
            ],
        ]);

        $redirect = 'http://127.0.0.1:30471/callback';
        self::assertSame($redirect, $clients->validateRedirect($client, $redirect, true));
        self::assertSame($redirect, $clients->validateRedirect($client, $redirect, false));
        self::assertNull($http->getLastRequest());

        // The registered redirect must also be allowed by the local document at exchange.
        $this->expectException(DiagnosticProblem::class);
        $this->expectExceptionMessage('OAuth client validation failed.');
        $clients->validateRedirect($client, self::REDIRECT, false);
    }

    public function testMalformedPreRegisteredMetadataIsRejectedWithoutFetching(): void
    {
        [$clients, $http] = $this->clients();
        $client = $clients->registered(self::CLIENT_ID) + ['client_metadata_document' => null];
        try {
            $clients->validateRedirect($client, self::REDIRECT, true);
            self::fail('Malformed pre-registered metadata should fail closed.');
        } catch (DiagnosticProblem $problem) {
            self::assertSame('invalid_client', $problem->reason);
            self::assertSame('oauth_client_metadata_invalid', $problem->logDetails()['failure_component']);
        }
        self::assertNull($http->getLastRequest());
    }

    public function testOnlineCimdRetainsHttpFailureForDiagnostics(): void
    {
        [$clients, $http] = $this->clients();
        $http->append(new Response(403, [], 'Blocked response body'));
        try {
            $clients->validateRedirect($clients->registered(self::CLIENT_ID), self::REDIRECT, true);
            self::fail('A failed metadata request should reject client authorization.');
        } catch (DiagnosticProblem $problem) {
            self::assertSame('OAuth client validation failed.', $problem->getMessage());
            self::assertSame('oauth_metadata_http_403', $problem->logDetails()['failure_component']);
        }
    }

    public function testPrivateKeyAssertionChecksClaimsAndReturnsReplayKey(): void
    {
        $key = $this->signingKey(['private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $jwks = ['keys' => [[
            'kty' => 'RSA', 'kid' => 'fixture-rsa', 'alg' => 'RS256',
            'n' => DiagnosticConfig::base64Url($rsa['n']), 'e' => DiagnosticConfig::base64Url($rsa['e']),
        ]]];
        [$clients, $http, $config] = $this->clients($jwks, $jwks);
        $tokenEndpoint = $config->issuer() . '/token';
        $request = (new ServerRequestFactory())->createServerRequest('POST', $tokenEndpoint);
        $client = $clients->registered(self::CLIENT_ID);
        $expires = time() + 60;
        $assertion = ['iss' => self::CLIENT_ID, 'sub' => self::CLIENT_ID, 'aud' => $tokenEndpoint, 'exp' => $expires, 'jti' => 'fixture-unique-id'];
        $input = [
            'client_id' => self::CLIENT_ID,
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => JWT::encode($assertion, $key, 'RS256', 'fixture-rsa'),
        ];
        self::assertSame([
            'hash' => hash('sha256', self::CLIENT_ID . "\0fixture-unique-id"), 'expires_at' => $expires,
        ], $clients->authenticate($client, $request, $input));
        self::assertSame(self::JWKS_URI, (string) $http->getLastRequest()->getUri());

        $this->expectException(DiagnosticProblem::class);
        $input['client_assertion'] = JWT::encode(array_replace($assertion, ['aud' => self::REDIRECT]), $key, 'RS256', 'fixture-rsa');
        $clients->authenticate($client, $request, $input);
    }

    public function testEs256AssertionUsesTheRegisteredCurveWhenJwkOmitsAlgorithm(): void
    {
        $key = $this->signingKey(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $ec = openssl_pkey_get_details($key)['ec'];
        $publicKey = [
            'kty' => 'EC', 'kid' => 'fixture-ec', 'crv' => 'P-256',
            'x' => DiagnosticConfig::base64Url(str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)),
            'y' => DiagnosticConfig::base64Url(str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
        ];
        [$clients, , $config] = $this->clients(
            ['keys' => [$publicKey]],
            ['keys' => [$publicKey + ['alg' => 'RS256']]]
        );
        self::assertSame(['RS256', 'ES256'], $clients->signingAlgorithms());
        $tokenEndpoint = $config->issuer() . '/token';
        $expires = time() + 60;
        $request = (new ServerRequestFactory())->createServerRequest('POST', $tokenEndpoint);
        $client = $clients->registered(self::CLIENT_ID);
        $input = [
            'client_id' => self::CLIENT_ID,
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => JWT::encode([
                'iss' => self::CLIENT_ID, 'sub' => self::CLIENT_ID, 'aud' => $tokenEndpoint,
                'exp' => $expires, 'jti' => 'fixture-ec-id',
            ], $key, 'ES256', 'fixture-ec'),
        ];
        self::assertSame([
            'hash' => hash('sha256', self::CLIENT_ID . "\0fixture-ec-id"), 'expires_at' => $expires,
        ], $clients->authenticate($client, $request, $input));

        $this->expectException(DiagnosticProblem::class);
        $this->expectExceptionMessage('OAuth client validation failed.');
        $clients->authenticate($client, $request, $input);
    }

    private function signingKey(array $options): \OpenSSLAsymmetricKey
    {
        $configPath = tempnam(sys_get_temp_dir(), 'ctdiag-openssl-');
        file_put_contents($configPath, "[req]\ndistinguished_name = dn\n[dn]\n");
        try {
            // PHP 8.2 validates a bit count before using the EC curve.
            $key = openssl_pkey_new($options + ['private_key_bits' => 2048, 'config' => $configPath]);
        } finally {
            unlink($configPath);
        }
        self::assertInstanceOf(\OpenSSLAsymmetricKey::class, $key);
        return $key;
    }

    private function clients(array ...$documents): array
    {
        $utc = new DateTimeZone('UTC');
        $config = new DiagnosticConfig(true, 'https://diagnostics.test', 'https://admin.diagnostics.test', str_repeat('k', 32),
            $utc, $utc, oauthClients: [[
                'id' => self::CLIENT_ID, 'name' => 'Fixture',
                'redirect_uris' => [self::REDIRECT],
                'auth_methods' => ['none', 'private_key_jwt'], 'jwks_uri' => self::JWKS_URI,
            ]]);
        $http = new MockHandler(array_map(static fn (array $document): Response => new Response(
            200, ['Content-Type' => 'application/json'], json_encode($document, JSON_THROW_ON_ERROR)
        ), $documents));
        return [new DiagnosticOAuthClients($config, new Client(['handler' => HandlerStack::create($http)])), $http, $config];
    }
}
