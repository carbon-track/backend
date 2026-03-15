<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Services;

use CarbonTrack\Models\UserPasskey;
use CarbonTrack\Models\WebauthnChallenge;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Services\NativeWebauthnProvider;
use CarbonTrack\Services\PasskeyConfig;
use CarbonTrack\Services\PasskeyOperationException;
use CarbonTrack\Services\PasskeyService;
use CarbonTrack\Services\RegionService;
use CarbonTrack\Services\WebauthnProviderInterface;
use CarbonTrack\Tests\Integration\TestSchemaBuilder;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;

class PasskeyServiceTest extends TestCase
{
    private PDO $pdo;
    private PasskeyConfig $config;
    private PasskeyService $service;
    private AuditLogService $auditLogService;
    private RegionService $regionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        TestSchemaBuilder::init($this->pdo);

        $this->config = new PasskeyConfig([
            'PASSKEYS_ENABLED' => 'true',
            'PASSKEYS_RP_ID' => 'app.example.test',
            'PASSKEYS_RP_NAME' => 'CarbonTrack Test',
            'PASSKEYS_ORIGINS' => 'https://app.example.test',
            'PASSKEYS_CHALLENGE_TTL_SECONDS' => '300',
            'PASSKEYS_REGISTRATION_TIMEOUT_MS' => '180000',
            'PASSKEYS_AUTHENTICATION_TIMEOUT_MS' => '120000',
        ]);

        $this->auditLogService = $this->createMock(AuditLogService::class);
        $this->auditLogService->method('log')->willReturn(true);

        $this->regionService = $this->createMock(RegionService::class);
        $this->regionService->method('getRegionContext')->willReturn([
            'region_code' => null,
            'region_label' => null,
            'country_code' => null,
            'state_code' => null,
        ]);

        $this->service = $this->createService(new NativeWebauthnProvider());
    }

    public function testBeginRegistrationStoresChallengeAndBuildsOptions(): void
    {
        $this->insertExistingPasskey();

        $result = $this->service->beginRegistration($this->userFixture(), [
            'label' => 'MacBook Pro',
        ]);

        $this->assertNotEmpty($result['challenge_id']);
        $this->assertSame('app.example.test', $result['public_key']['rp']['id']);
        $this->assertSame('CarbonTrack Test', $result['public_key']['rp']['name']);
        $this->assertCount(1, $result['public_key']['excludeCredentials']);
        $this->assertSame('existing-credential', $result['public_key']['excludeCredentials'][0]['id']);
        $this->assertTrue($result['integration']['available']);
        $this->assertSame('native', $result['integration']['implementation']);

        $challengeStmt = $this->pdo->prepare('SELECT * FROM webauthn_challenges WHERE challenge_id = :challenge_id');
        $challengeStmt->execute(['challenge_id' => $result['challenge_id']]);
        $stored = $challengeStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($stored);
        $this->assertSame('registration', $stored['flow_type']);
        $this->assertSame('550e8400-e29b-41d4-a716-4466554400aa', $stored['user_uuid']);
        $this->assertNotEmpty($stored['challenge']);
    }

    public function testCompleteRegistrationValidatesAndPersistsPasskey(): void
    {
        $registration = $this->service->beginRegistration($this->userFixture(), [
            'label' => 'Desk Key',
        ]);
        $keyPair = $this->generateEcKeyPair();
        $credential = $this->buildRegistrationCredential(
            $registration['public_key']['challenge'],
            'https://app.example.test',
            $keyPair['x'],
            $keyPair['y'],
            'credential-registration-1'
        );

        $result = $this->service->completeRegistration($this->userFixture(), [
            'challenge_id' => $registration['challenge_id'],
            'credential' => $credential,
        ]);

        $expectedCredentialId = $this->base64UrlEncode('credential-registration-1');
        $this->assertSame('Desk Key', $result['label']);
        $this->assertSame($expectedCredentialId, $result['credential_id']);
        $this->assertSame(0, $result['sign_count']);

        $stored = $this->pdo->query('SELECT * FROM user_passkeys WHERE credential_id = "' . $expectedCredentialId . '"')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($stored);
        $publicKey = json_decode((string) $stored['public_key'], true);
        $this->assertSame('EC2', $publicKey['kty']);
        $this->assertSame(-7, $publicKey['alg']);
        $this->assertSame('internal', json_decode((string) $stored['transports'], true)[0]);
    }

    public function testCompleteRegistrationParsesCredentialPublicKeyWhenExtensionsFollow(): void
    {
        $registration = $this->service->beginRegistration($this->userFixture(), [
            'label' => 'Desk Key With Extensions',
        ]);
        $keyPair = $this->generateEcKeyPair();
        $credentialIdBytes = 'credential-registration-ext-1';

        $result = $this->service->completeRegistration($this->userFixture(), [
            'challenge_id' => $registration['challenge_id'],
            'credential' => $this->buildRegistrationCredential(
                $registration['public_key']['challenge'],
                'https://app.example.test',
                $keyPair['x'],
                $keyPair['y'],
                $credentialIdBytes,
                $this->cborMap([
                    'credProtect' => 1,
                ])
            ),
        ]);

        $expectedCredentialId = $this->base64UrlEncode($credentialIdBytes);
        $this->assertSame($expectedCredentialId, $result['credential_id']);

        $stored = $this->pdo->query('SELECT public_key FROM user_passkeys WHERE credential_id = "' . $expectedCredentialId . '"')->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($stored);
        $publicKey = json_decode((string) $stored['public_key'], true);
        $this->assertSame('EC2', $publicKey['kty']);
        $this->assertSame(-7, $publicKey['alg']);
    }

    public function testCompleteAuthenticationVerifiesAssertionAndUpdatesCounter(): void
    {
        $registration = $this->service->beginRegistration($this->userFixture(), [
            'label' => 'Phone',
        ]);
        $keyPair = $this->generateEcKeyPair();
        $credentialIdBytes = 'credential-auth-1';
        $credentialId = $this->base64UrlEncode($credentialIdBytes);

        $this->service->completeRegistration($this->userFixture(), [
            'challenge_id' => $registration['challenge_id'],
            'credential' => $this->buildRegistrationCredential(
                $registration['public_key']['challenge'],
                'https://app.example.test',
                $keyPair['x'],
                $keyPair['y'],
                $credentialIdBytes
            ),
        ]);

        $authentication = $this->service->beginAuthentication([
            'identifier' => 'admin@testdomain.com',
        ]);

        $this->assertCount(1, $authentication['public_key']['allowCredentials']);
        $this->assertSame($credentialId, $authentication['public_key']['allowCredentials'][0]['id']);

        $result = $this->service->completeAuthentication([
            'challenge_id' => $authentication['challenge_id'],
            'credential' => $this->buildAuthenticationCredential(
                $authentication['public_key']['challenge'],
                'https://app.example.test',
                $credentialId,
                $keyPair['private_key'],
                'NTUwZTg0MDAtZTI5Yi00MWQ0LWE3MTYtNDQ2NjU1NDQwMGFh',
                2
            ),
        ]);

        $this->assertSame('admin_user', $result['user']['username']);
        $this->assertSame($credentialId, $result['passkey']['credential_id']);
        $this->assertSame(2, $result['passkey']['sign_count']);
        $this->assertNotEmpty($result['passkey']['last_used_at']);

        $stored = $this->pdo->query('SELECT sign_count, last_used_at FROM user_passkeys WHERE credential_id = "' . $credentialId . '"')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('2', (string) $stored['sign_count']);
        $this->assertNotEmpty($stored['last_used_at']);
    }

    public function testBeginRegistrationRequiresPersistedUserUuid(): void
    {
        try {
            $this->service->beginRegistration([
                'id' => 1,
                'uuid' => null,
                'username' => 'admin_user',
                'email' => 'admin@testdomain.com',
            ]);
            $this->fail('Expected PasskeyOperationException was not thrown.');
        } catch (PasskeyOperationException $exception) {
            $this->assertSame('USER_UUID_REQUIRED', $exception->getErrorCode());
            $this->assertSame(409, $exception->getHttpStatus());
        }
    }

    public function testBeginAuthenticationReturnsGenericOptionsForUnknownIdentifier(): void
    {
        $result = $this->service->beginAuthentication([
            'identifier' => 'missing@testdomain.com',
        ]);

        $this->assertNotEmpty($result['challenge_id']);
        $this->assertArrayNotHasKey('allowCredentials', $result['public_key']);
    }

    public function testBeginAuthenticationAllowsOmittingIdentifier(): void
    {
        $result = $this->service->beginAuthentication();

        $this->assertNotEmpty($result['challenge_id']);
        $this->assertArrayNotHasKey('allowCredentials', $result['public_key']);
    }

    public function testBeginAuthenticationReturnsGenericOptionsWhenAccountHasNoPasskeys(): void
    {
        $result = $this->service->beginAuthentication([
            'identifier' => 'admin@testdomain.com',
        ]);

        $this->assertNotEmpty($result['challenge_id']);
        $this->assertArrayNotHasKey('allowCredentials', $result['public_key']);
    }

    public function testCompleteAuthenticationRejectsCredentialWhenIdentifierDoesNotMatch(): void
    {
        $credentialId = 'credential-auth-mismatch';
        $mockProvider = $this->createMock(WebauthnProviderInterface::class);
        $mockProvider->method('isAvailable')->willReturn(true);
        $mockProvider->method('getMetadata')->willReturn([
            'available' => true,
            'implementation' => 'mock',
        ]);
        $mockProvider->expects($this->once())
            ->method('verifyAuthenticationResponse')
            ->with(
                $this->callback(static fn (array $credential): bool => ($credential['id'] ?? null) === $credentialId),
                $this->isType('array'),
                $this->callback(static fn (array $passkey): bool => ($passkey['credential_id'] ?? null) === $credentialId),
                $this->isInstanceOf(PasskeyConfig::class)
            )
            ->willReturn([
                'credential_id' => $credentialId,
                'sign_count' => 2,
                'backup_state' => false,
                'last_used_at' => gmdate('Y-m-d H:i:s'),
            ]);
        $mockProvider->expects($this->never())->method('verifyRegistrationResponse');

        $service = $this->createService($mockProvider);
        $this->insertPasskeyForUser(1, $credentialId, 'Phone', 1);

        $authentication = $service->beginAuthentication([
            'identifier' => 'missing@testdomain.com',
        ]);

        try {
            $service->completeAuthentication([
                'challenge_id' => $authentication['challenge_id'],
                'credential' => [
                    'id' => $credentialId,
                    'rawId' => $credentialId,
                    'type' => 'public-key',
                    'response' => [],
                ],
            ]);
            $this->fail('Expected PasskeyOperationException was not thrown.');
        } catch (PasskeyOperationException $exception) {
            $this->assertSame('PASSKEY_ACCOUNT_MISMATCH', $exception->getErrorCode());
            $this->assertSame(401, $exception->getHttpStatus());
        }
    }

    public function testCompleteAuthenticationReturnsNotFoundForUnknownCredential(): void
    {
        $authentication = $this->service->beginAuthentication();

        try {
            $this->service->completeAuthentication([
                'challenge_id' => $authentication['challenge_id'],
                'credential' => [
                    'id' => 'missing-credential',
                ],
            ]);
            $this->fail('Expected PasskeyOperationException was not thrown.');
        } catch (PasskeyOperationException $exception) {
            $this->assertSame('PASSKEY_NOT_FOUND', $exception->getErrorCode());
            $this->assertSame(404, $exception->getHttpStatus());
        }
    }

    public function testUpdateLabelForUserTrimsValueAndWritesAuditEvent(): void
    {
        $this->insertExistingPasskey();
        $passkeyId = (int) $this->pdo
            ->query("SELECT id FROM user_passkeys WHERE credential_id = 'existing-credential'")
            ->fetchColumn();

        $expectedLabel = str_repeat('A', 100);
        $this->auditLogService->expects($this->once())
            ->method('log')
            ->with($this->callback(function (array $payload) use ($passkeyId, $expectedLabel): bool {
                $this->assertSame('passkey_label_updated', $payload['action']);
                $this->assertSame(1, $payload['user_id']);
                $this->assertSame('success', $payload['status']);
                $this->assertSame($passkeyId, $payload['data']['passkey_id']);
                $this->assertSame($expectedLabel, $payload['data']['new_label']);
                return true;
            }))
            ->willReturn(true);

        $updated = $this->service->updateLabelForUser($this->userFixture(), $passkeyId, str_repeat('A', 120));

        $this->assertSame($expectedLabel, $updated['label']);
        $storedLabel = $this->pdo
            ->query("SELECT label FROM user_passkeys WHERE id = {$passkeyId}")
            ->fetchColumn();
        $this->assertSame($expectedLabel, $storedLabel);
    }

    public function testUpdateLabelForUserSkipsNoOpUpdates(): void
    {
        $this->insertExistingPasskey();
        $passkeyId = (int) $this->pdo
            ->query("SELECT id FROM user_passkeys WHERE credential_id = 'existing-credential'")
            ->fetchColumn();
        $originalUpdatedAt = (string) $this->pdo
            ->query("SELECT updated_at FROM user_passkeys WHERE id = {$passkeyId}")
            ->fetchColumn();

        $this->auditLogService->expects($this->never())->method('log');

        $updated = $this->service->updateLabelForUser($this->userFixture(), $passkeyId, '  Existing laptop  ');

        $this->assertSame('Existing laptop', $updated['label']);
        $storedUpdatedAt = (string) $this->pdo
            ->query("SELECT updated_at FROM user_passkeys WHERE id = {$passkeyId}")
            ->fetchColumn();
        $this->assertSame($originalUpdatedAt, $storedUpdatedAt);
    }

    public function testListForAdminReturnsFilteredSortedPasskeys(): void
    {
        $this->insertPasskeyForUser(1, 'admin-credential', 'Admin Laptop', 2, '2026-03-10 08:00:00');

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password, school_id, status, points, is_admin, uuid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            'bob',
            'bob@example.com',
            password_hash('password123', PASSWORD_BCRYPT),
            1,
            'active',
            50,
            0,
            '550e8400-e29b-41d4-a716-4466554400bb',
        ]);
        $bobId = (int) $this->pdo->lastInsertId();
        $this->insertPasskeyForUser($bobId, 'bob-credential', 'Bob Phone', 9, '2026-03-10 09:00:00', true);

        $result = $this->service->listForAdmin(1, [
            'q' => 'bob',
            'sort' => 'sign_count_desc',
            'page' => 1,
            'limit' => 10,
        ]);

        $this->assertSame(1, $result['pagination']['total_items']);
        $this->assertCount(1, $result['passkeys']);
        $this->assertSame('bob', $result['passkeys'][0]['username']);
        $this->assertSame('bob@example.com', $result['passkeys'][0]['email']);
        $this->assertSame('Bob Phone', $result['passkeys'][0]['label']);
        $this->assertSame(9, $result['passkeys'][0]['sign_count']);
        $this->assertTrue($result['passkeys'][0]['backup_state']);
    }

    public function testGetAdminStatsCountsActivePasskeysAndRecentPasskeyLogins(): void
    {
        $this->insertPasskeyForUser(1, 'admin-credential', 'Admin Laptop', 2, gmdate('Y-m-d H:i:s', strtotime('-2 days')));
        $this->insertPasskeyForUser(1, 'admin-credential-2', 'Admin Phone', 4, gmdate('Y-m-d H:i:s', strtotime('-18 days')));

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (username, email, password, school_id, status, points, is_admin, uuid)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            'retired-user',
            'retired@example.com',
            password_hash('password123', PASSWORD_BCRYPT),
            1,
            'inactive',
            0,
            0,
            '550e8400-e29b-41d4-a716-4466554400bc',
        ]);
        $deletedUserId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('UPDATE users SET deleted_at = ? WHERE id = ?')
            ->execute([gmdate('Y-m-d H:i:s', strtotime('-1 day')), $deletedUserId]);
        $this->insertPasskeyForUser($deletedUserId, 'deleted-user-credential', 'Old Device', 8, gmdate('Y-m-d H:i:s', strtotime('-3 days')));

        $stmt = $this->pdo->prepare(
            "INSERT INTO audit_logs (user_id, actor_type, action, status, operation_category, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([1, 'user', 'passkey_login', 'success', 'authentication', gmdate('Y-m-d H:i:s', strtotime('-2 days'))]);
        $stmt->execute([1, 'user', 'passkey_login', 'success', 'authentication', gmdate('Y-m-d H:i:s', strtotime('-10 days'))]);
        $stmt->execute([1, 'user', 'passkey_login', 'success', 'authentication', gmdate('Y-m-d H:i:s', strtotime('-40 days'))]);

        $stats = $this->service->getAdminStats(1);

        $this->assertSame(1, $stats['users_with_passkeys']);
        $this->assertSame(2, $stats['total_active_passkeys']);
        $this->assertSame(2, $stats['new_passkeys_30d']);
        $this->assertSame(1, $stats['passkey_logins_7d']);
        $this->assertSame(2, $stats['passkey_logins_30d']);
    }

    private function userFixture(): array
    {
        return [
            'id' => 1,
            'uuid' => '550e8400-e29b-41d4-a716-4466554400aa',
            'username' => 'admin_user',
            'email' => 'admin@testdomain.com',
            'points' => 1000,
            'is_admin' => true,
        ];
    }

    private function createService(WebauthnProviderInterface $webauthnProvider): PasskeyService
    {
        return new PasskeyService(
            $this->config,
            new UserPasskey($this->pdo),
            new WebauthnChallenge($this->pdo),
            $webauthnProvider,
            $this->auditLogService,
            $this->pdo,
            $this->regionService,
            null,
            null,
            $this->createMock(ErrorLogService::class),
            $this->createMock(Logger::class)
        );
    }

    private function insertExistingPasskey(): void
    {
        $this->insertPasskeyForUser(1, 'existing-credential', 'Existing laptop', 7);
    }

    private function insertPasskeyForUser(
        int $userId,
        string $credentialId,
        string $label,
        int $signCount,
        ?string $createdAt = null,
        bool $backupState = false
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_passkeys (
                user_uuid, credential_id, credential_id_hash, credential_type, label, public_key, rp_id, user_handle,
                transports, aaguid, sign_count, attestation_format, backup_eligible, backup_state, meta_json,
                last_used_at, attested_at, created_at, updated_at
            ) VALUES (
                :user_uuid, :credential_id, :credential_id_hash, :credential_type, :label, :public_key, :rp_id, :user_handle,
                :transports, :aaguid, :sign_count, :attestation_format, :backup_eligible, :backup_state, :meta_json,
                :last_used_at, :attested_at, :created_at, :updated_at
            )'
        );
        $timestamp = $createdAt ?? gmdate('Y-m-d H:i:s');
        $stmt->execute([
            'user_uuid' => $this->userUuidForId($userId),
            'credential_id' => $credentialId,
            'credential_id_hash' => hash('sha256', $credentialId),
            'credential_type' => 'public-key',
            'label' => $label,
            'public_key' => json_encode(['pem' => 'placeholder', 'alg' => -7]),
            'rp_id' => 'app.example.test',
            'user_handle' => 'dGVzdC11c2Vy',
            'transports' => json_encode(['internal']),
            'aaguid' => null,
            'sign_count' => $signCount,
            'attestation_format' => null,
            'backup_eligible' => 0,
            'backup_state' => $backupState ? 1 : 0,
            'meta_json' => null,
            'last_used_at' => $timestamp,
            'attested_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function userUuidForId(int $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT uuid FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $uuid = $stmt->fetchColumn();
        $this->assertNotFalse($uuid);

        return strtolower((string) $uuid);
    }

    /**
     * @return array{private_key:resource|\OpenSSLAsymmetricKey,x:string,y:string}
     */
    private function generateEcKeyPair(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($privateKey === false) {
            $this->markTestSkipped('OpenSSL EC key generation is not available in this environment.');
        }
        $details = openssl_pkey_get_details($privateKey);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            $this->markTestSkipped('OpenSSL EC key details are not available in this environment.');
        }

        return [
            'private_key' => $privateKey,
            'x' => $details['ec']['x'],
            'y' => $details['ec']['y'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRegistrationCredential(
        string $challenge,
        string $origin,
        string $x,
        string $y,
        string $credentialIdBytes,
        ?array $extensions = null
    ): array {
        $clientDataJson = json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $credentialPublicKey = $this->cborEncode($this->cborMap([
            1 => 2,
            3 => -7,
            -1 => 1,
            -2 => $this->cborBytes($x),
            -3 => $this->cborBytes($y),
        ]));

        $flags = 0x45;
        $extensionData = '';
        if ($extensions !== null) {
            $flags |= 0x80;
            $extensionData = $this->cborEncode($extensions);
        }

        $authenticatorData = hash('sha256', 'app.example.test', true)
            . chr($flags)
            . pack('N', 0)
            . str_repeat("\x00", 16)
            . pack('n', strlen($credentialIdBytes))
            . $credentialIdBytes
            . $credentialPublicKey
            . $extensionData;

        $attestationObject = $this->cborEncode($this->cborMap([
            'fmt' => 'none',
            'attStmt' => $this->cborMap([]),
            'authData' => $this->cborBytes($authenticatorData),
        ]));

        $credentialId = $this->base64UrlEncode($credentialIdBytes);

        return [
            'id' => $credentialId,
            'rawId' => $credentialId,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->base64UrlEncode($clientDataJson),
                'attestationObject' => $this->base64UrlEncode($attestationObject),
            ],
            'authenticatorAttachment' => 'platform',
        ];
    }

    /**
     * @param resource|\OpenSSLAsymmetricKey $privateKey
     * @return array<string, mixed>
     */
    private function buildAuthenticationCredential(
        string $challenge,
        string $origin,
        string $credentialId,
        $privateKey,
        string $userHandle,
        int $signCount
    ): array {
        $clientDataJson = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => $origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);

        $authenticatorData = hash('sha256', 'app.example.test', true)
            . chr(0x05)
            . pack('N', $signCount);
        $signaturePayload = $authenticatorData . hash('sha256', $clientDataJson, true);
        openssl_sign($signaturePayload, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return [
            'id' => $credentialId,
            'rawId' => $credentialId,
            'type' => 'public-key',
            'response' => [
                'authenticatorData' => $this->base64UrlEncode($authenticatorData),
                'clientDataJSON' => $this->base64UrlEncode($clientDataJson),
                'signature' => $this->base64UrlEncode($signature),
                'userHandle' => $userHandle,
            ],
        ];
    }

    /**
     * @param mixed $value
     */
    private function cborEncode($value): string
    {
        if (is_array($value) && array_key_exists('__bytes', $value)) {
            return $this->encodeCborItem(2, (string) $value['__bytes']);
        }

        if (is_array($value) && array_key_exists('__map', $value)) {
            $encoded = '';
            foreach ($value['__map'] as $key => $item) {
                $encoded .= $this->cborEncode($key) . $this->cborEncode($item);
            }
            return $this->encodeCborHeader(5, count($value['__map'])) . $encoded;
        }

        if (is_string($value)) {
            return $this->encodeCborItem(3, $value);
        }

        if (is_int($value)) {
            if ($value >= 0) {
                return $this->encodeCborHeader(0, $value);
            }

            return $this->encodeCborHeader(1, (-1 - $value));
        }

        if (is_bool($value)) {
            return $value ? "\xf5" : "\xf4";
        }

        if ($value === null) {
            return "\xf6";
        }

        throw new \InvalidArgumentException('Unsupported CBOR test value.');
    }

    /**
     * @return array{__bytes:string}
     */
    private function cborBytes(string $value): array
    {
        return ['__bytes' => $value];
    }

    /**
     * @return array{__map:array<mixed,mixed>}
     */
    private function cborMap(array $value): array
    {
        return ['__map' => $value];
    }

    private function encodeCborItem(int $majorType, string $payload): string
    {
        return $this->encodeCborHeader($majorType, strlen($payload)) . $payload;
    }

    private function encodeCborHeader(int $majorType, int $value): string
    {
        if ($value < 24) {
            return chr(($majorType << 5) | $value);
        }

        if ($value < 256) {
            return chr(($majorType << 5) | 24) . chr($value);
        }

        if ($value < 65536) {
            return chr(($majorType << 5) | 25) . pack('n', $value);
        }

        return chr(($majorType << 5) | 26) . pack('N', $value);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
