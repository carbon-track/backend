<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Services\Webauthn\Base64Url;
use CarbonTrack\Services\Webauthn\CborDecoder;

class NativeWebauthnProvider implements WebauthnProviderInterface
{
    private const FLAG_USER_PRESENT = 0x01;
    private const FLAG_USER_VERIFIED = 0x04;
    private const FLAG_BACKUP_ELIGIBLE = 0x08;
    private const FLAG_BACKUP_STATE = 0x10;
    private const FLAG_ATTESTED_CREDENTIAL_DATA = 0x40;

    public function isAvailable(): bool
    {
        return extension_loaded('openssl');
    }

    public function getMetadata(): array
    {
        return [
            'package' => null,
            'implementation' => 'native',
            'available' => $this->isAvailable(),
            'expected_extension' => 'openssl',
        ];
    }

    public function verifyRegistrationResponse(
        array $credential,
        array $challengeRecord,
        array $user,
        PasskeyConfig $config
    ): array {
        unset($user);

        $this->ensureAvailable();

        try {
            $clientData = $this->decodeClientData($credential, 'webauthn.create', (string) $challengeRecord['challenge'], $config);
            $attestationObject = $this->decodeRequiredResponseField($credential, 'attestationObject');
            $decodedAttestation = CborDecoder::decode($attestationObject);
            if (!is_array($decodedAttestation)) {
                throw new \InvalidArgumentException('Attestation object must decode to a CBOR map.');
            }

            $fmt = (string) ($decodedAttestation['fmt'] ?? '');
            $authDataBinary = $decodedAttestation['authData'] ?? null;
            if (!is_string($authDataBinary) || $authDataBinary === '') {
                throw new \InvalidArgumentException('Attestation authData is missing.');
            }

            $parsedAuthData = $this->parseAuthenticatorData($authDataBinary, true);
            $this->assertRpIdHash($parsedAuthData['rp_id_hash'], $config->getRpId());
            $this->assertUserPresence($parsedAuthData['user_present']);

            if ($config->getUserVerificationPreference() === 'required') {
                $this->assertUserVerification($parsedAuthData['user_verified']);
            }

            $credentialPublicKeyBytes = $parsedAuthData['credential_public_key'] ?? null;
            if (!is_string($credentialPublicKeyBytes) || $credentialPublicKeyBytes === '') {
                throw new \InvalidArgumentException('Credential public key is missing.');
            }

            $normalizedKey = $this->normalizeCredentialPublicKey($credentialPublicKeyBytes);
            $this->verifyAttestationStatement(
                $fmt,
                is_array($decodedAttestation['attStmt'] ?? null) ? $decodedAttestation['attStmt'] : [],
                $authDataBinary,
                $clientData['hash'],
                $normalizedKey
            );

            return [
                'credential_id' => $parsedAuthData['credential_id'],
                'credential_type' => 'public-key',
                'public_key' => json_encode($normalizedKey, JSON_UNESCAPED_SLASHES),
                'rp_id' => $config->getRpId(),
                'user_handle' => is_array($challengeRecord['context'] ?? null)
                    ? (string) (($challengeRecord['context']['user_handle'] ?? '') ?: '')
                    : '',
                'transports' => $this->extractTransports($credential),
                'aaguid' => $parsedAuthData['aaguid'],
                'sign_count' => $parsedAuthData['sign_count'],
                'attestation_format' => $fmt !== '' ? $fmt : 'none',
                'backup_eligible' => $parsedAuthData['backup_eligible'],
                'backup_state' => $parsedAuthData['backup_state'],
                'meta' => [
                    'provider' => 'native',
                    'public_key_algorithm' => $normalizedKey['alg'] ?? null,
                    'credential_public_key' => Base64Url::encode($credentialPublicKeyBytes),
                    'authenticator_attachment' => $credential['authenticatorAttachment'] ?? null,
                ],
                'attested_at' => gmdate('Y-m-d H:i:s'),
            ];
        } catch (PasskeyOperationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new PasskeyOperationException(
                'Passkey registration response validation failed.',
                'INVALID_REGISTRATION_RESPONSE',
                400,
                $exception
            );
        }
    }

    public function verifyAuthenticationResponse(
        array $credential,
        array $challengeRecord,
        array $passkey,
        PasskeyConfig $config
    ): array {
        $this->ensureAvailable();

        try {
            $clientData = $this->decodeClientData($credential, 'webauthn.get', (string) $challengeRecord['challenge'], $config);
            $authenticatorData = $this->decodeRequiredResponseField($credential, 'authenticatorData');
            $signature = $this->decodeRequiredResponseField($credential, 'signature');
            $parsedAuthData = $this->parseAuthenticatorData($authenticatorData, false);

            $this->assertRpIdHash($parsedAuthData['rp_id_hash'], (string) ($passkey['rp_id'] ?? $config->getRpId()));
            $this->assertUserPresence($parsedAuthData['user_present']);

            if ($config->getUserVerificationPreference() === 'required') {
                $this->assertUserVerification($parsedAuthData['user_verified']);
            }

            $expectedUserHandle = trim((string) ($passkey['user_handle'] ?? ''));
            $presentedUserHandle = null;
            if (isset($credential['response']['userHandle']) && $credential['response']['userHandle'] !== null) {
                $presentedUserHandle = Base64Url::encode(Base64Url::decode((string) $credential['response']['userHandle']));
            }

            if ($presentedUserHandle !== null && $expectedUserHandle !== '' && !hash_equals($expectedUserHandle, $presentedUserHandle)) {
                throw new PasskeyOperationException('Passkey user handle mismatch.', 'INVALID_USER_HANDLE', 400);
            }

            $normalizedKey = $this->loadStoredPublicKey((string) ($passkey['public_key'] ?? ''));
            $signedPayload = $authenticatorData . $clientData['hash'];
            if (!$this->verifySignature($signedPayload, $signature, $normalizedKey, (int) ($normalizedKey['alg'] ?? 0))) {
                throw new PasskeyOperationException('Passkey signature verification failed.', 'INVALID_SIGNATURE', 401);
            }

            $storedSignCount = (int) ($passkey['sign_count'] ?? 0);
            $newSignCount = (int) ($parsedAuthData['sign_count'] ?? 0);
            if ($storedSignCount > 0 && $newSignCount > 0 && $newSignCount <= $storedSignCount) {
                throw new PasskeyOperationException('Authenticator sign count did not advance.', 'SIGN_COUNT_REPLAY', 401);
            }

            return [
                'credential_id' => $this->extractCredentialId($credential),
                'sign_count' => $newSignCount,
                'user_handle' => $expectedUserHandle,
                'backup_eligible' => $parsedAuthData['backup_eligible'],
                'backup_state' => $parsedAuthData['backup_state'],
                'last_used_at' => gmdate('Y-m-d H:i:s'),
            ];
        } catch (PasskeyOperationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new PasskeyOperationException(
                'Passkey authentication response validation failed.',
                'INVALID_AUTHENTICATION_RESPONSE',
                401,
                $exception
            );
        }
    }

    private function ensureAvailable(): void
    {
        if (!$this->isAvailable()) {
            throw new PasskeyIntegrationUnavailableException('native-openssl');
        }
    }

    private function decodeClientData(
        array $credential,
        string $expectedType,
        string $expectedChallenge,
        PasskeyConfig $config
    ): array {
        $clientDataBinary = $this->decodeRequiredResponseField($credential, 'clientDataJSON');
        $clientData = json_decode($clientDataBinary, true);
        if (!is_array($clientData)) {
            throw new \InvalidArgumentException('Client data JSON is invalid.');
        }

        if (($clientData['type'] ?? null) !== $expectedType) {
            throw new PasskeyOperationException('Unexpected WebAuthn ceremony type.', 'INVALID_CEREMONY_TYPE', 400);
        }

        if (!hash_equals($expectedChallenge, (string) ($clientData['challenge'] ?? ''))) {
            throw new PasskeyOperationException('WebAuthn challenge mismatch.', 'INVALID_CHALLENGE', 400);
        }

        $origin = trim((string) ($clientData['origin'] ?? ''));
        if ($origin === '' || !$this->isAllowedOrigin($origin, $config->getAllowedOrigins())) {
            throw new PasskeyOperationException('WebAuthn origin is not allowed.', 'INVALID_ORIGIN', 400);
        }

        if (!empty($clientData['crossOrigin'])) {
            throw new PasskeyOperationException('Cross-origin WebAuthn responses are not allowed.', 'CROSS_ORIGIN_NOT_ALLOWED', 400);
        }

        return [
            'json' => $clientData,
            'binary' => $clientDataBinary,
            'hash' => hash('sha256', $clientDataBinary, true),
        ];
    }

    private function isAllowedOrigin(string $origin, array $allowedOrigins): bool
    {
        foreach ($allowedOrigins as $allowedOrigin) {
            if (hash_equals(rtrim($allowedOrigin, '/'), rtrim($origin, '/'))) {
                return true;
            }
        }

        return false;
    }

    private function decodeRequiredResponseField(array $credential, string $field): string
    {
        $value = $credential['response'][$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(sprintf('Credential response field %s is missing.', $field));
        }

        return Base64Url::decode($value);
    }

    private function extractCredentialId(array $credential): string
    {
        foreach (['rawId', 'id'] as $field) {
            $value = $credential[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        throw new PasskeyOperationException('Credential id is required.', 'MISSING_CREDENTIAL_ID', 400);
    }

    private function parseAuthenticatorData(string $authData, bool $requireAttestedCredentialData): array
    {
        if (strlen($authData) < 37) {
            throw new \InvalidArgumentException('Authenticator data is too short.');
        }

        $rpIdHash = substr($authData, 0, 32);
        $flags = ord($authData[32]);
        $signCount = unpack('N', substr($authData, 33, 4))[1];
        $offset = 37;

        $aaguid = null;
        $credentialId = null;
        $credentialPublicKey = null;

        if (($flags & self::FLAG_ATTESTED_CREDENTIAL_DATA) !== 0) {
            if (strlen($authData) < ($offset + 18)) {
                throw new \InvalidArgumentException('Authenticator attested credential data is incomplete.');
            }

            $aaguid = $this->formatUuidLikeHex(substr($authData, $offset, 16));
            $offset += 16;
            $credentialIdLength = unpack('n', substr($authData, $offset, 2))[1];
            $offset += 2;

            $credentialId = substr($authData, $offset, $credentialIdLength);
            $offset += $credentialIdLength;
            if ($credentialId === false || $credentialId === '') {
                throw new \InvalidArgumentException('Credential id is missing from authenticator data.');
            }

            $credentialPublicKey = substr($authData, $offset);
            if ($credentialPublicKey === false || $credentialPublicKey === '') {
                throw new \InvalidArgumentException('Credential public key is missing from authenticator data.');
            }
        } elseif ($requireAttestedCredentialData) {
            throw new \InvalidArgumentException('Authenticator data is missing attested credential data.');
        }

        return [
            'rp_id_hash' => $rpIdHash,
            'sign_count' => (int) $signCount,
            'user_present' => ($flags & self::FLAG_USER_PRESENT) !== 0,
            'user_verified' => ($flags & self::FLAG_USER_VERIFIED) !== 0,
            'backup_eligible' => ($flags & self::FLAG_BACKUP_ELIGIBLE) !== 0,
            'backup_state' => ($flags & self::FLAG_BACKUP_STATE) !== 0,
            'aaguid' => $aaguid,
            'credential_id' => $credentialId !== null ? Base64Url::encode($credentialId) : null,
            'credential_public_key' => $credentialPublicKey,
        ];
    }

    private function verifyAttestationStatement(
        string $format,
        array $attestationStatement,
        string $authDataBinary,
        string $clientDataHash,
        array $normalizedKey
    ): void {
        if ($format === '' || $format === 'none') {
            return;
        }

        if ($format !== 'packed') {
            throw new PasskeyOperationException('Unsupported attestation format.', 'UNSUPPORTED_ATTESTATION_FORMAT', 400);
        }

        $alg = isset($attestationStatement['alg']) && is_int($attestationStatement['alg'])
            ? $attestationStatement['alg']
            : (int) ($normalizedKey['alg'] ?? 0);
        $signature = $attestationStatement['sig'] ?? null;
        if (!is_string($signature) || $signature === '') {
            throw new PasskeyOperationException('Packed attestation signature is missing.', 'INVALID_ATTESTATION', 400);
        }

        $signedPayload = $authDataBinary . $clientDataHash;
        $verificationKey = $normalizedKey;
        if (isset($attestationStatement['x5c']) && is_array($attestationStatement['x5c']) && isset($attestationStatement['x5c'][0]) && is_string($attestationStatement['x5c'][0])) {
            $verificationKey = [
                'alg' => $alg,
                'pem' => $this->derToPemCertificate($attestationStatement['x5c'][0]),
            ];
        }

        if (!$this->verifySignature($signedPayload, $signature, $verificationKey, $alg)) {
            throw new PasskeyOperationException('Packed attestation signature verification failed.', 'INVALID_ATTESTATION', 400);
        }
    }

    private function normalizeCredentialPublicKey(string $credentialPublicKeyBytes): array
    {
        $coseKey = CborDecoder::decode($credentialPublicKeyBytes);
        if (!is_array($coseKey)) {
            throw new \InvalidArgumentException('Credential public key CBOR must decode to a map.');
        }

        $kty = (int) ($coseKey[1] ?? 0);
        $alg = (int) ($coseKey[3] ?? 0);

        if ($kty === 2) {
            $curve = (int) ($coseKey[-1] ?? 0);
            $x = $coseKey[-2] ?? null;
            $y = $coseKey[-3] ?? null;
            if (!is_string($x) || !is_string($y)) {
                throw new \InvalidArgumentException('EC2 credential public key is incomplete.');
            }

            return [
                'alg' => $alg,
                'kty' => 'EC2',
                'curve' => $this->mapEcCurve($curve),
                'x' => Base64Url::encode($x),
                'y' => Base64Url::encode($y),
                'pem' => $this->buildEcPublicKeyPem($curve, $x, $y),
            ];
        }

        if ($kty === 3) {
            $modulus = $coseKey[-1] ?? null;
            $exponent = $coseKey[-2] ?? null;
            if (!is_string($modulus) || !is_string($exponent)) {
                throw new \InvalidArgumentException('RSA credential public key is incomplete.');
            }

            return [
                'alg' => $alg,
                'kty' => 'RSA',
                'n' => Base64Url::encode($modulus),
                'e' => Base64Url::encode($exponent),
                'pem' => $this->buildRsaPublicKeyPem($modulus, $exponent),
            ];
        }

        throw new PasskeyOperationException('Unsupported credential public key type.', 'UNSUPPORTED_PUBLIC_KEY', 400);
    }

    private function loadStoredPublicKey(string $value): array
    {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (strpos($value, 'BEGIN PUBLIC KEY') !== false) {
            return [
                'alg' => -7,
                'pem' => $value,
            ];
        }

        throw new PasskeyOperationException('Stored passkey public key is invalid.', 'INVALID_STORED_PUBLIC_KEY', 500);
    }

    private function verifySignature(string $payload, string $signature, array $verificationKey, int $alg): bool
    {
        $pem = $verificationKey['pem'] ?? null;
        if (!is_string($pem) || $pem === '') {
            return false;
        }

        $opensslAlgorithm = $this->mapOpenSslAlgorithm($alg);
        if ($opensslAlgorithm === null) {
            return false;
        }

        return openssl_verify($payload, $signature, $pem, $opensslAlgorithm) === 1;
    }

    private function mapOpenSslAlgorithm(int $alg): ?int
    {
        if ($alg === -7) {
            return OPENSSL_ALGO_SHA256;
        }

        if ($alg === -257) {
            return OPENSSL_ALGO_SHA256;
        }

        return null;
    }

    private function assertRpIdHash(string $presentedHash, string $rpId): void
    {
        if (!hash_equals(hash('sha256', strtolower($rpId), true), $presentedHash)) {
            throw new PasskeyOperationException('Relying party id hash mismatch.', 'INVALID_RP_ID', 400);
        }
    }

    private function assertUserPresence(bool $userPresent): void
    {
        if (!$userPresent) {
            throw new PasskeyOperationException('Authenticator did not signal user presence.', 'USER_PRESENCE_REQUIRED', 400);
        }
    }

    private function assertUserVerification(bool $userVerified): void
    {
        if (!$userVerified) {
            throw new PasskeyOperationException('Authenticator did not complete user verification.', 'USER_VERIFICATION_REQUIRED', 400);
        }
    }

    /**
     * @return string[]
     */
    private function extractTransports(array $credential): array
    {
        $transports = [];
        if (isset($credential['response']['transports']) && is_array($credential['response']['transports'])) {
            $transports = $credential['response']['transports'];
        }

        if ($transports === [] && isset($credential['authenticatorAttachment']) && is_string($credential['authenticatorAttachment'])) {
            $transports[] = $credential['authenticatorAttachment'] === 'platform' ? 'internal' : $credential['authenticatorAttachment'];
        }

        return array_values(array_unique(array_filter(array_map('strval', $transports), static fn (string $transport): bool => $transport !== '')));
    }

    private function mapEcCurve(int $curve): string
    {
        if ($curve === 1) {
            return 'P-256';
        }

        if ($curve === 2) {
            return 'P-384';
        }

        if ($curve === 3) {
            return 'P-521';
        }

        throw new PasskeyOperationException('Unsupported EC public key curve.', 'UNSUPPORTED_PUBLIC_KEY', 400);
    }

    private function buildEcPublicKeyPem(int $curve, string $x, string $y): string
    {
        if ($curve !== 1) {
            throw new PasskeyOperationException('Only P-256 passkeys are currently supported.', 'UNSUPPORTED_PUBLIC_KEY', 400);
        }

        $algorithm = $this->derSequence(
            $this->derOid('1.2.840.10045.2.1'),
            $this->derOid('1.2.840.10045.3.1.7')
        );
        $publicKey = "\x04" . $x . $y;
        $spki = $this->derSequence(
            $algorithm,
            $this->derBitString($publicKey)
        );

        return $this->derToPemPublicKey($spki);
    }

    private function buildRsaPublicKeyPem(string $modulus, string $exponent): string
    {
        $rsaPublicKey = $this->derSequence(
            $this->derInteger($modulus),
            $this->derInteger($exponent)
        );
        $algorithm = $this->derSequence(
            $this->derOid('1.2.840.113549.1.1.1'),
            $this->derNull()
        );
        $spki = $this->derSequence(
            $algorithm,
            $this->derBitString($rsaPublicKey)
        );

        return $this->derToPemPublicKey($spki);
    }

    private function derToPemPublicKey(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    private function derToPemCertificate(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }

    private function derSequence(string ...$parts): string
    {
        $payload = implode('', $parts);
        return "\x30" . $this->derLength(strlen($payload)) . $payload;
    }

    private function derBitString(string $payload): string
    {
        return "\x03" . $this->derLength(strlen($payload) + 1) . "\x00" . $payload;
    }

    private function derInteger(string $payload): string
    {
        if ($payload === '' || (ord($payload[0]) & 0x80) !== 0) {
            $payload = "\x00" . $payload;
        }

        return "\x02" . $this->derLength(strlen($payload)) . $payload;
    }

    private function derNull(): string
    {
        return "\x05\x00";
    }

    private function derOid(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        $first = (40 * $parts[0]) + $parts[1];
        $encoded = chr($first);
        for ($index = 2, $count = count($parts); $index < $count; $index++) {
            $encoded .= $this->encodeBase128($parts[$index]);
        }

        return "\x06" . $this->derLength(strlen($encoded)) . $encoded;
    }

    private function encodeBase128(int $value): string
    {
        $bytes = [chr($value & 0x7f)];
        $value >>= 7;
        while ($value > 0) {
            array_unshift($bytes, chr(($value & 0x7f) | 0x80));
            $value >>= 7;
        }

        return implode('', $bytes);
    }

    private function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private function formatUuidLikeHex(string $binary): string
    {
        $hex = bin2hex($binary);
        return substr($hex, 0, 8)
            . '-' . substr($hex, 8, 4)
            . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4)
            . '-' . substr($hex, 20, 12);
    }
}
