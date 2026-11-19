<?php

declare(strict_types=1);

namespace CarbonTrack\Services\LogDiagnostics;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DiagnosticOAuthClients
{
    private const METHODS = ['none', 'client_secret_basic', 'client_secret_post', 'private_key_jwt'];
    private const ASSERTION_ALGORITHMS = ['RSA' => 'RS256', 'EC' => 'ES256'];

    public function __construct(
        private readonly DiagnosticConfig $config,
        private readonly ClientInterface $http
    ) {
    }

    public function methods(): array
    {
        $methods = [];
        foreach ($this->config->oauthClients as $client) {
            if (is_array($client)) {
                $methods = array_merge($methods, $this->allowedMethods($client));
            }
        }
        return array_values(array_unique($methods));
    }

    public function supportsCimd(): bool
    {
        foreach ($this->config->oauthClients as $client) {
            if (is_array($client) && isset($client['id']) && self::httpsUrl($client['id'])) {
                return true;
            }
        }
        return false;
    }

    public function signingAlgorithms(): array
    {
        return array_values(self::ASSERTION_ALGORITHMS);
    }

    public function registered(mixed $id): array
    {
        if (is_string($id) && strlen($id) <= 512) {
            foreach ($this->config->oauthClients as $client) {
                if (is_array($client) && ($client['id'] ?? null) === $id && is_array($client['redirect_uris'] ?? null)
                    && $this->allowedMethods($client) !== []) {
                    return $client;
                }
            }
        }
        throw self::invalid();
    }

    public function validateRedirect(array $client, mixed $uri, bool $checkCimd): string
    {
        if (!is_string($uri) || strlen($uri) > 1024 || !in_array($uri, $client['redirect_uris'], true)
            || (!self::httpsUrl($uri) && !self::localRedirect($uri))) {
            throw new DiagnosticProblem(400, 'invalid_request', 'The OAuth redirect URI is invalid.');
        }
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $query);
        if (array_intersect(['code', 'error', 'state', 'iss'], array_keys($query)) !== []) {
            throw new DiagnosticProblem(400, 'invalid_request', 'The OAuth redirect URI contains a reserved parameter.');
        }
        if ($checkCimd && self::httpsUrl($client['id'])) {
            $document = $this->document($client['id']);
            $methods = $document['token_endpoint_auth_methods_supported']
                ?? [$document['token_endpoint_auth_method'] ?? 'none'];
            $allowed = $this->allowedMethods($client);
            if (($document['client_id'] ?? null) !== $client['id'] || !is_string($document['client_name'] ?? null)
                || !is_array($document['redirect_uris'] ?? null) || !in_array($uri, $document['redirect_uris'], true)
                || !is_array($methods) || array_diff($allowed, array_filter($methods, 'is_string')) !== []
                || (in_array('private_key_jwt', $allowed, true) && ($document['jwks_uri'] ?? null) !== ($client['jwks_uri'] ?? null))) {
                throw self::invalid();
            }
        }
        return $uri;
    }

    public function authenticate(array $client, Request $request, array $input): ?array
    {
        $header = $request->getHeaderLine('Authorization');
        $bodyId = $input['client_id'] ?? null;
        if ($bodyId !== null && $bodyId !== $client['id']) {
            throw self::invalid();
        }
        $secret = $input['client_secret'] ?? null;
        $assertion = $input['client_assertion'] ?? null;
        $method = match (true) {
            $assertion !== null => 'private_key_jwt',
            $header !== '' => 'client_secret_basic',
            $secret !== null => 'client_secret_post',
            default => 'none',
        };
        if (!in_array($method, $this->allowedMethods($client), true)) {
            throw self::invalid();
        }

        if ($method === 'none') {
            if ($bodyId !== $client['id'] || $secret !== null || $header !== '') {
                throw self::invalid();
            }
            return null;
        }
        if ($method === 'private_key_jwt') {
            if (!is_string($assertion) || strlen($assertion) > 12288 || $secret !== null || $header !== ''
                || ($input['client_assertion_type'] ?? null) !== 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer') {
                throw self::invalid();
            }
            return $this->validateAssertion($client, $assertion);
        }
        if ($method === 'client_secret_basic') {
            $basic = preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/iD', $header, $matches) === 1
                ? base64_decode($matches[1], true) : false;
            $parts = is_string($basic) ? explode(':', $basic, 2) : [];
            if ($secret !== null || count($parts) !== 2 || urldecode($parts[0]) !== $client['id']) {
                throw self::invalid();
            }
            $secret = urldecode($parts[1]);
        } elseif ($header !== '' || $bodyId !== $client['id']) {
            throw self::invalid();
        }
        if (!is_string($secret) || $secret === '' || strlen($secret) > 1024
            || !is_string($client['secret_sha256'] ?? null) || !hash_equals($client['secret_sha256'], hash('sha256', $secret))) {
            throw self::invalid();
        }

        return null;
    }

    private function allowedMethods(array $client): array
    {
        $configured = $client['auth_methods'] ?? [$client['auth_method'] ?? null];
        if (!is_array($configured)) {
            return [];
        }
        return array_values(array_intersect(self::METHODS, array_filter($configured, 'is_string')));
    }

    private function validateAssertion(array $client, string $assertion): array
    {
        $jwks = $this->document($client['jwks_uri'] ?? '');
        try {
            $claims = JWT::decode($assertion, JWK::parseKeySet(['keys' => self::assertionKeys($jwks)]));
        } catch (\Throwable) {
            throw self::invalid();
        }
        $now = time();
        $audience = $this->config->issuer() . '/token';
        if (($claims->iss ?? null) !== $client['id'] || ($claims->sub ?? null) !== $client['id']
            || !in_array($claims->aud ?? null, [$audience, [$audience]], true)
            || !is_int($claims->exp ?? null) || $claims->exp <= $now || $claims->exp > $now + 300
            || !is_string($claims->jti ?? null) || $claims->jti === '' || strlen($claims->jti) > 256) {
            throw self::invalid();
        }
        return ['hash' => hash('sha256', $client['id'] . "\0" . $claims->jti), 'expires_at' => $claims->exp];
    }

    private static function assertionKeys(array $jwks): array
    {
        if (!is_array($jwks['keys'] ?? null)) {
            throw self::invalid();
        }
        $keys = [];
        foreach ($jwks['keys'] as $index => $key) {
            if (!is_array($key) || !is_string($key['kty'] ?? null)) {
                continue;
            }
            $algorithm = self::ASSERTION_ALGORITHMS[$key['kty']] ?? null;
            if ($algorithm === null
                || ($key['kty'] === 'EC' && ($key['crv'] ?? null) !== 'P-256')
                || (array_key_exists('alg', $key) && $key['alg'] !== $algorithm)) {
                continue;
            }
            $key['alg'] = $algorithm;
            $keys[$index] = $key;
        }
        return $keys;
    }

    private function document(mixed $url): array
    {
        if (!self::httpsUrl($url)) {
            throw self::invalid();
        }
        $host = parse_url($url, PHP_URL_HOST);
        $addresses = gethostbynamel($host) ?: [];
        $address = $addresses[0] ?? '';
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            throw self::invalid();
        }
        try {
            $response = $this->http->request('GET', $url, [
                RequestOptions::ALLOW_REDIRECTS => false,
                RequestOptions::CONNECT_TIMEOUT => 2,
                RequestOptions::TIMEOUT => 4,
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::PROXY => '',
                'curl' => [CURLOPT_RESOLVE => [$host . ':443:' . $address]],
                'headers' => ['Accept' => 'application/json'],
                RequestOptions::ON_HEADERS => static function ($response): void {
                    if ((int) $response->getHeaderLine('Content-Length') > 65536) {
                        throw self::invalid();
                    }
                },
                RequestOptions::PROGRESS => static function ($total, $downloaded): void {
                    if ($downloaded > 65536) {
                        throw self::invalid();
                    }
                },
            ]);
            $body = $response->getBody();
            $encoded = $body->read(65537);
            $document = json_decode($encoded, true);
            if ($response->getStatusCode() === 200 && strlen($encoded) <= 65536 && $body->eof() && is_array($document)) {
                return $document;
            }
        } catch (\Throwable) {
            // Metadata is part of the client's identity, so unavailable data fails closed.
        }

        throw self::invalid();
    }

    private static function localRedirect(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    private static function httpsUrl(mixed $url): bool
    {
        $parts = is_string($url) ? parse_url($url) : false;
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment'])
            && ($parts['port'] ?? 443) === 443 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function invalid(): DiagnosticProblem
    {
        return new DiagnosticProblem(401, 'invalid_client', 'OAuth client validation failed.');
    }
}
