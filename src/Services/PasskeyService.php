<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\UserPasskey;
use CarbonTrack\Models\WebauthnChallenge;
use CarbonTrack\Services\Webauthn\Base64Url;
use CarbonTrack\Support\Uuid;
use Monolog\Logger;
use PDO;

class PasskeyService
{
    private const FLOW_AUTHENTICATION = 'authentication';
    private const FLOW_REGISTRATION = 'registration';
    private const ADMIN_PASSKEY_SORTS = [
        'created_at_desc',
        'last_used_at_desc',
        'sign_count_desc',
    ];
    private UserProfileViewService $userProfileViewService;

    public function __construct(
        private PasskeyConfig $config,
        private UserPasskey $userPasskeyModel,
        private WebauthnChallenge $challengeModel,
        private WebauthnProviderInterface $webauthnProvider,
        private AuditLogService $auditLogService,
        private PDO $db,
        private RegionService $regionService,
        private ?CheckinService $checkinService = null,
        private ?CloudflareR2Service $r2Service = null,
        private ?ErrorLogService $errorLogService = null,
        private ?Logger $logger = null,
        ?UserProfileViewService $userProfileViewService = null
    ) {
        $this->userProfileViewService = $userProfileViewService ?? new UserProfileViewService($regionService);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(array $user): array
    {
        $userId = $this->requireUserId($user);
        $userUuid = $this->requireUserUuid($user);
        $passkeys = $this->userPasskeyModel->listActiveByUserUuid($userUuid);

        $this->auditLogService->log([
            'action' => 'passkey_list_viewed',
            'operation_category' => 'authentication',
            'user_id' => $userId,
            'actor_type' => 'user',
            'affected_table' => 'user_passkeys',
            'status' => 'success',
            'change_type' => 'read',
            'data' => ['count' => count($passkeys)],
        ]);

        return array_map([$this, 'toPublicSummary'], $passkeys);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function beginRegistration(array $user, array $payload = []): array
    {
        $this->ensureEnabled();

        $userId = $this->requireUserId($user);
        $userUuid = $this->requireUserUuid($user);
        $this->challengeModel->deleteExpired();

        $label = $this->sanitizeLabel($payload['label'] ?? null);
        $passkeys = $this->userPasskeyModel->listActiveByUserUuid($userUuid);
        $challengeId = Uuid::generateV4();
        $challenge = Base64Url::encode(random_bytes(32));
        $userHandle = $this->resolveUserHandle($user);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $this->config->getChallengeTtlSeconds());

        $this->challengeModel->create([
            'challenge_id' => $challengeId,
            'user_uuid' => $userUuid,
            'flow_type' => self::FLOW_REGISTRATION,
            'challenge' => $challenge,
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? ($_SERVER['REQUEST_ID'] ?? null),
            'context' => [
                'label' => $label,
                'username' => $user['username'] ?? null,
                'email' => $user['email'] ?? null,
                'user_handle' => $userHandle,
                'rp_id' => $this->config->getRpId(),
            ],
            'expires_at' => $expiresAt,
        ]);

        $options = $this->buildRegistrationOptions($user, $userHandle, $challenge, $passkeys);

        $this->logPasskeyEvent('passkey_registration_options_created', $userId, 'success', [
            'challenge_id' => $challengeId,
            'exclude_credentials' => count($passkeys),
            'label' => $label,
            'integration_available' => $this->webauthnProvider->isAvailable(),
        ], 'create', 'webauthn_challenges');

        return [
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt,
            'public_key' => $options,
            'integration' => $this->buildIntegrationMetadata(),
        ];
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function completeRegistration(array $user, array $payload): array
    {
        $this->ensureEnabled();

        $userId = $this->requireUserId($user);
        $userUuid = $this->requireUserUuid($user);
        $challengeId = $this->requireChallengeId($payload);
        $credential = $this->requireCredentialPayload($payload);

        $challengeRecord = $this->challengeModel->findActive($challengeId, self::FLOW_REGISTRATION, $userUuid);
        if ($challengeRecord === null) {
            $this->logPasskeyEvent('passkey_registration_failed', $userId, 'failed', [
                'challenge_id' => $challengeId,
                'reason' => 'challenge_not_found',
            ]);
            throw new PasskeyOperationException('Passkey challenge was not found or has expired.', 'CHALLENGE_NOT_FOUND', 404);
        }

        $verified = $this->webauthnProvider->verifyRegistrationResponse(
            $credential,
            $challengeRecord,
            $user,
            $this->config
        );

        if (!$this->challengeModel->markConsumed((int) $challengeRecord['id'])) {
            throw new PasskeyOperationException('Challenge has already been consumed or expired.', 'PASSKEY_CHALLENGE_CONSUMED', 400);
        }

        $credentialId = trim((string) ($verified['credential_id'] ?? ''));
        if ($credentialId === '') {
            throw new PasskeyOperationException('Verified passkey response did not contain a credential id.', 'INVALID_CREDENTIAL', 400);
        }

        if ($this->userPasskeyModel->findActiveByCredentialId($credentialId) !== null) {
            $this->logPasskeyEvent('passkey_registration_failed', $userId, 'failed', [
                'challenge_id' => $challengeId,
                'reason' => 'duplicate_credential',
                'credential_id' => $credentialId,
            ]);
            throw new PasskeyOperationException('This passkey is already registered.', 'PASSKEY_ALREADY_EXISTS', 409);
        }

        $context = is_array($challengeRecord['context'] ?? null) ? $challengeRecord['context'] : [];
        $created = $this->userPasskeyModel->create([
            'user_uuid' => $userUuid,
            'credential_id' => $credentialId,
            'credential_type' => $verified['credential_type'] ?? 'public-key',
            'label' => $this->sanitizeLabel($payload['label'] ?? ($context['label'] ?? null)),
            'public_key' => (string) ($verified['public_key'] ?? ''),
            'rp_id' => (string) ($verified['rp_id'] ?? $this->config->getRpId()),
            'user_handle' => (string) ($verified['user_handle'] ?? ($context['user_handle'] ?? $this->resolveUserHandle($user))),
            'transports' => is_array($verified['transports'] ?? null) ? $verified['transports'] : [],
            'aaguid' => $verified['aaguid'] ?? null,
            'sign_count' => (int) ($verified['sign_count'] ?? 0),
            'attestation_format' => $verified['attestation_format'] ?? null,
            'backup_eligible' => !empty($verified['backup_eligible']),
            'backup_state' => !empty($verified['backup_state']),
            'meta' => is_array($verified['meta'] ?? null) ? $verified['meta'] : null,
            'last_used_at' => $verified['last_used_at'] ?? null,
            'attested_at' => $verified['attested_at'] ?? null,
        ]);

        if (empty($created)) {
            throw new PasskeyOperationException('Passkey registration could not be stored.', 'PASSKEY_PERSIST_FAILED', 500);
        }

        $this->logPasskeyEvent('passkey_registered', $userId, 'success', [
            'challenge_id' => $challengeId,
            'passkey_id' => $created['id'] ?? null,
            'label' => $created['label'] ?? null,
        ], 'create', 'user_passkeys');

        return $this->toPublicSummary($created);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function beginAuthentication(array $payload = []): array
    {
        $this->ensureEnabled();
        $this->challengeModel->deleteExpired();

        $identifier = $this->sanitizeIdentifier($payload['identifier'] ?? null);
        $user = null;
        $passkeys = [];
        $userUuid = null;
        $auditUserId = null;

        if ($identifier !== null) {
            $user = $this->findUserByIdentifier($identifier);
            if ($user !== null && $this->userHasValidUuid($user)) {
                $candidateUserUuid = strtolower((string) $user['uuid']);
                $candidatePasskeys = $this->userPasskeyModel->listActiveByUserUuid($candidateUserUuid);
                if ($candidatePasskeys !== []) {
                    $userUuid = $candidateUserUuid;
                    $auditUserId = (int) $user['id'];
                    $passkeys = $candidatePasskeys;
                }
            }
        }

        $challengeId = Uuid::generateV4();
        $challenge = Base64Url::encode(random_bytes(32));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $this->config->getChallengeTtlSeconds());
        $this->challengeModel->create([
            'challenge_id' => $challengeId,
            'user_uuid' => $userUuid,
            'flow_type' => self::FLOW_AUTHENTICATION,
            'challenge' => $challenge,
            'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? ($_SERVER['REQUEST_ID'] ?? null),
            'context' => [
                'identifier' => $identifier,
                'rp_id' => $this->config->getRpId(),
            ],
            'expires_at' => $expiresAt,
        ]);

        if ($auditUserId !== null) {
            $this->auditLogService->log([
                'action' => 'passkey_authentication_options_created',
                'operation_category' => 'authentication',
                'user_id' => $auditUserId,
                'actor_type' => 'user',
                'affected_table' => 'webauthn_challenges',
                'status' => 'success',
                'change_type' => 'create',
                'data' => [
                    'challenge_id' => $challengeId,
                    'allow_credentials' => count($passkeys),
                ],
            ]);
        }

        return [
            'challenge_id' => $challengeId,
            'expires_at' => $expiresAt,
            'public_key' => $this->buildAuthenticationOptions($challenge, $passkeys),
            'integration' => $this->buildIntegrationMetadata(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function completeAuthentication(array $payload): array
    {
        $this->ensureEnabled();

        $challengeId = $this->requireChallengeId($payload);
        $credential = $this->requireCredentialPayload($payload);
        $challengeRecord = $this->challengeModel->findActive($challengeId, self::FLOW_AUTHENTICATION);
        if ($challengeRecord === null) {
            throw new PasskeyOperationException('Passkey challenge was not found or has expired.', 'CHALLENGE_NOT_FOUND', 404);
        }

        $credentialId = $this->extractCredentialId($credential);
        $passkey = $this->userPasskeyModel->findActiveByCredentialId($credentialId);
        if ($passkey === null) {
            throw new PasskeyOperationException('Passkey credential was not found.', 'PASSKEY_NOT_FOUND', 404);
        }

        if (
            isset($challengeRecord['user_uuid'])
            && $challengeRecord['user_uuid'] !== null
            && strcasecmp((string) $challengeRecord['user_uuid'], (string) ($passkey['user_uuid'] ?? '')) !== 0
        ) {
            throw new PasskeyOperationException('Passkey credential does not match the challenged account.', 'PASSKEY_ACCOUNT_MISMATCH', 401);
        }

        $verified = $this->webauthnProvider->verifyAuthenticationResponse(
            $credential,
            $challengeRecord,
            $passkey,
            $this->config
        );

        if (!$this->challengeModel->markConsumed((int) $challengeRecord['id'])) {
            throw new PasskeyOperationException('Challenge has already been consumed or expired.', 'PASSKEY_CHALLENGE_CONSUMED', 400);
        }

        $updated = $this->userPasskeyModel->touchAuthentication(
            (int) $passkey['id'],
            (int) ($verified['sign_count'] ?? (int) ($passkey['sign_count'] ?? 0)),
            !empty($verified['backup_state']),
            $verified['last_used_at'] ?? gmdate('Y-m-d H:i:s')
        );
        if (!$updated) {
            throw new PasskeyOperationException('Passkey authentication state could not be updated.', 'PASSKEY_TOUCH_FAILED', 500);
        }

        $user = $this->findUserDetailedByUuid((string) ($passkey['user_uuid'] ?? ''));
        if ($user === null) {
            throw new PasskeyOperationException('The passkey owner account was not found.', 'PASSKEY_USER_NOT_FOUND', 404);
        }
        $this->requireUserUuid($user);

        $context = is_array($challengeRecord['context'] ?? null) ? $challengeRecord['context'] : [];
        $identifier = $this->sanitizeIdentifier($context['identifier'] ?? null);
        if ($identifier !== null && !$this->userMatchesIdentifier($user, $identifier)) {
            throw new PasskeyOperationException('Passkey credential does not match the challenged account.', 'PASSKEY_ACCOUNT_MISMATCH', 401);
        }

        $this->touchUserLogin((int) $user['id']);
        if ($this->checkinService !== null) {
            try {
                $this->checkinService->syncUserCheckinsFromRecords((int) $user['id']);
            } catch (\Throwable $exception) {
                if ($this->logger !== null) {
                    $this->logger->debug('Failed to sync user checkins on passkey login', [
                        'error' => $exception->getMessage(),
                        'user_id' => $user['id'],
                    ]);
                }
            }
        }

        $this->auditLogService->log([
            'action' => 'passkey_login',
            'operation_category' => 'authentication',
            'user_id' => $user['id'],
            'actor_type' => 'user',
            'affected_table' => 'user_passkeys',
            'status' => 'success',
            'change_type' => 'update',
            'data' => [
                'passkey_id' => $passkey['id'],
                'credential_id' => $credentialId,
            ],
        ]);

        $freshPasskey = $this->userPasskeyModel->findActiveByCredentialId($credentialId) ?? $passkey;

        return [
            'user' => $this->formatUserPayload($user),
            'passkey' => $this->toPublicSummary($freshPasskey),
        ];
    }

    /**
     * @param array<string, mixed> $user
     */
    public function deleteForUser(array $user, int $passkeyId): void
    {
        $this->ensureEnabled();

        $userId = $this->requireUserId($user);
        $userUuid = $this->requireUserUuid($user);

        $passkey = $this->userPasskeyModel->findActiveByIdForUserUuid($passkeyId, $userUuid);
        if ($passkey === null) {
            throw new PasskeyOperationException('Passkey was not found.', 'PASSKEY_NOT_FOUND', 404);
        }

        if (!$this->userPasskeyModel->disable($passkeyId, $userUuid)) {
            throw new PasskeyOperationException('Passkey could not be deleted.', 'PASSKEY_DELETE_FAILED', 500);
        }

        $this->logPasskeyEvent('passkey_deleted', $userId, 'success', [
            'passkey_id' => $passkeyId,
            'credential_id' => $passkey['credential_id'] ?? null,
        ], 'delete', 'user_passkeys');
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    public function updateLabelForUser(array $user, int $passkeyId, ?string $label): array
    {
        $userId = $this->requireUserId($user);
        $userUuid = $this->requireUserUuid($user);

        $passkey = $this->userPasskeyModel->findActiveByIdForUserUuid($passkeyId, $userUuid);
        if ($passkey === null) {
            throw new PasskeyOperationException('Passkey was not found.', 'PASSKEY_NOT_FOUND', 404);
        }

        $sanitizedLabel = $this->sanitizeLabel($label);
        $currentLabel = $this->sanitizeLabel($passkey['label'] ?? null);
        if ($sanitizedLabel === $currentLabel) {
            return $this->toPublicSummary($passkey);
        }

        $updated = $this->userPasskeyModel->updateLabel($passkeyId, $userUuid, $sanitizedLabel);
        if ($updated === null) {
            throw new PasskeyOperationException('Passkey label could not be updated.', 'PASSKEY_LABEL_UPDATE_FAILED', 500);
        }

        $this->logPasskeyEvent('passkey_label_updated', $userId, 'success', [
            'passkey_id' => $passkeyId,
            'credential_id' => $passkey['credential_id'] ?? null,
            'old_label' => $passkey['label'] ?? null,
            'new_label' => $updated['label'] ?? null,
        ], 'update', 'user_passkeys');

        return $this->toPublicSummary($updated);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listForAdmin(int $adminId, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $search = trim((string) ($filters['q'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'created_at_desc');
        if (!in_array($sort, self::ADMIN_PASSKEY_SORTS, true)) {
            $sort = 'created_at_desc';
        }

        $result = $this->userPasskeyModel->listAdminPasskeys($search, $limit, $offset, $sort);
        $items = $result['items'] ?? [];
        $total = (int) ($result['total'] ?? 0);

        $this->auditLogService->log([
            'action' => 'admin_passkeys_viewed',
            'operation_category' => 'admin',
            'user_id' => $adminId,
            'actor_type' => 'admin',
            'affected_table' => 'user_passkeys',
            'status' => 'success',
            'change_type' => 'read',
            'data' => [
                'q' => $search,
                'page' => $page,
                'limit' => $limit,
                'sort' => $sort,
                'count' => count($items),
            ],
        ]);

        return [
            'passkeys' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_items' => $total,
                'total_pages' => $total > 0 ? (int) ceil($total / $limit) : 0,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAdminStats(int $adminId): array
    {
        $since7Days = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
        $since30Days = gmdate('Y-m-d H:i:s', strtotime('-30 days'));
        $stats = $this->userPasskeyModel->getAdminPasskeyStats($since30Days);
        $stats['passkey_logins_7d'] = $this->countAuditActionSince('passkey_login', $since7Days);
        $stats['passkey_logins_30d'] = $this->countAuditActionSince('passkey_login', $since30Days);

        $this->auditLogService->log([
            'action' => 'admin_passkey_stats_viewed',
            'operation_category' => 'admin',
            'user_id' => $adminId,
            'actor_type' => 'admin',
            'affected_table' => 'user_passkeys',
            'status' => 'success',
            'change_type' => 'read',
            'data' => $stats,
        ]);

        return $stats;
    }

    /**
     * @return array<string, mixed>
     */
    public function getUserPasskeySummary(string $userUuid): array
    {
        return $this->userPasskeyModel->getUserPasskeySummary($userUuid);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<int, array<string, mixed>> $existingPasskeys
     * @return array<string, mixed>
     */
    private function buildRegistrationOptions(array $user, string $userHandle, string $challenge, array $existingPasskeys): array
    {
        $authenticatorSelection = [
            'residentKey' => $this->config->getResidentKeyPreference(),
            'userVerification' => $this->config->getUserVerificationPreference(),
        ];

        $attachment = $this->config->getAuthenticatorAttachment();
        if ($attachment !== null) {
            $authenticatorSelection['authenticatorAttachment'] = $attachment;
        }

        return [
            'rp' => [
                'name' => $this->config->getRpName(),
                'id' => $this->config->getRpId(),
            ],
            'user' => [
                'id' => $userHandle,
                'name' => (string) ($user['email'] ?? $user['username'] ?? ('user-' . $this->requireUserId($user))),
                'displayName' => (string) ($user['username'] ?? $user['email'] ?? ('user-' . $this->requireUserId($user))),
            ],
            'challenge' => $challenge,
            'pubKeyCredParams' => array_map(
                static fn (int $alg): array => ['type' => 'public-key', 'alg' => $alg],
                $this->config->getAllowedAlgorithms()
            ),
            'timeout' => $this->config->getRegistrationTimeoutMs(),
            'attestation' => $this->config->getAttestationPreference(),
            'authenticatorSelection' => $authenticatorSelection,
            'excludeCredentials' => array_map([$this, 'mapCredentialDescriptor'], $existingPasskeys),
            'extensions' => [
                'credProps' => true,
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $passkeys
     * @return array<string, mixed>
     */
    private function buildAuthenticationOptions(string $challenge, array $passkeys): array
    {
        $options = [
            'challenge' => $challenge,
            'rpId' => $this->config->getRpId(),
            'timeout' => $this->config->getAuthenticationTimeoutMs(),
            'userVerification' => $this->config->getUserVerificationPreference(),
        ];

        if ($passkeys !== []) {
            $options['allowCredentials'] = array_map([$this, 'mapCredentialDescriptor'], $passkeys);
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $passkey
     * @return array<string, mixed>
     */
    private function mapCredentialDescriptor(array $passkey): array
    {
        return [
            'type' => 'public-key',
            'id' => (string) ($passkey['credential_id'] ?? ''),
            'transports' => is_array($passkey['transports'] ?? null) && $passkey['transports'] !== []
                ? array_values($passkey['transports'])
                : $this->config->getDefaultTransports(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIntegrationMetadata(): array
    {
        return array_merge($this->webauthnProvider->getMetadata(), [
            'enabled' => $this->config->isEnabled(),
            'rp_id' => $this->config->getRpId(),
            'rp_name' => $this->config->getRpName(),
            'allowed_origins' => $this->config->getAllowedOrigins(),
        ]);
    }

    /**
     * @param array<string, mixed> $passkey
     * @return array<string, mixed>
     */
    private function toPublicSummary(array $passkey): array
    {
        return [
            'id' => isset($passkey['id']) ? (int) $passkey['id'] : null,
            'label' => $passkey['label'] ?? null,
            'credential_id' => $passkey['credential_id'] ?? null,
            'credential_type' => $passkey['credential_type'] ?? 'public-key',
            'rp_id' => $passkey['rp_id'] ?? null,
            'user_handle' => $passkey['user_handle'] ?? null,
            'transports' => is_array($passkey['transports'] ?? null) ? $passkey['transports'] : [],
            'aaguid' => $passkey['aaguid'] ?? null,
            'sign_count' => (int) ($passkey['sign_count'] ?? 0),
            'last_used_at' => $passkey['last_used_at'] ?? null,
            'attested_at' => $passkey['attested_at'] ?? null,
            'backup_eligible' => (bool) ($passkey['backup_eligible'] ?? false),
            'backup_state' => (bool) ($passkey['backup_state'] ?? false),
            'created_at' => $passkey['created_at'] ?? null,
            'updated_at' => $passkey['updated_at'] ?? null,
        ];
    }

    private function ensureEnabled(): void
    {
        if (!$this->config->isEnabled()) {
            throw new PasskeyOperationException('Passkeys are disabled by configuration.', 'PASSKEYS_DISABLED', 503);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireChallengeId(array $payload): string
    {
        $challengeId = trim((string) ($payload['challenge_id'] ?? ''));
        if ($challengeId === '') {
            throw new PasskeyOperationException('challenge_id is required.', 'CHALLENGE_ID_REQUIRED', 400);
        }

        return $challengeId;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function requireCredentialPayload(array $payload): array
    {
        $credential = $payload['credential'] ?? $payload;
        if (!is_array($credential)) {
            throw new PasskeyOperationException('credential must be an object.', 'INVALID_CREDENTIAL', 400);
        }

        return $credential;
    }

    private function extractCredentialId(array $credential): string
    {
        $credentialId = trim((string) ($credential['rawId'] ?? $credential['id'] ?? ''));
        if ($credentialId === '') {
            throw new PasskeyOperationException('Credential id is required.', 'MISSING_CREDENTIAL_ID', 400);
        }

        return $credentialId;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function requireUserId(array $user): int
    {
        $userId = $user['id'] ?? null;
        if (!is_numeric($userId) || (int) $userId <= 0) {
            throw new PasskeyOperationException('Authenticated user id is missing.', 'INVALID_USER', 400);
        }

        return (int) $userId;
    }

    /**
     * @param mixed $value
     */
    private function sanitizeLabel($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $label = trim((string) $value);
        if ($label === '') {
            return null;
        }

        return mb_substr($label, 0, 100);
    }

    /**
     * @param mixed $value
     */
    private function sanitizeIdentifier($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $identifier = trim((string) $value);
        if ($identifier === '') {
            return null;
        }

        return mb_substr($identifier, 0, 255);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function resolveUserHandle(array $user): string
    {
        return Base64Url::encode($this->requireUserUuid($user));
    }

    /**
     * @param array<string, mixed> $user
     */
    private function requireUserUuid(array $user): string
    {
        $uuid = trim((string) ($user['uuid'] ?? ''));
        if ($uuid === '' || !Uuid::isValid($uuid)) {
            throw new PasskeyOperationException(
                'Passkey operations require a valid persisted user UUID.',
                'USER_UUID_REQUIRED',
                409
            );
        }

        return strtolower($uuid);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function userHasValidUuid(array $user): bool
    {
        $uuid = trim((string) ($user['uuid'] ?? ''));

        return $uuid !== '' && Uuid::isValid($uuid);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function userMatchesIdentifier(array $user, string $identifier): bool
    {
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false) {
            return strcasecmp((string) ($user['email'] ?? ''), $identifier) === 0;
        }

        return strcasecmp((string) ($user['username'] ?? ''), $identifier) === 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserByIdentifier(string $identifier): ?array
    {
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) !== false ? 'u.email' : 'u.username';
        $stmt = $this->db->prepare(
            "SELECT u.*, s.name AS school_name, a.file_path AS avatar_path
             FROM users u
             LEFT JOIN schools s ON u.school_id = s.id
             LEFT JOIN avatars a ON u.avatar_id = a.id
             WHERE {$field} = ? AND u.deleted_at IS NULL
             LIMIT 1"
        );
        $stmt->execute([$identifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        return $user ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserDetailedByUuid(string $userUuid): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT u.*, s.name AS school_name, a.file_path AS avatar_path
             FROM users u
             LEFT JOIN schools s ON u.school_id = s.id
             LEFT JOIN avatars a ON u.avatar_id = a.id
             WHERE u.uuid = ? AND u.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([strtolower($userUuid)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function touchUserLogin(int $userId): void
    {
        $timestamp = gmdate('Y-m-d H:i:s');

        try {
            $stmt = $this->db->prepare('UPDATE users SET lastlgn = ? WHERE id = ?');
            $stmt->execute([$timestamp, $userId]);
            return;
        } catch (\Throwable $exception) {
            if ($this->logger !== null) {
                $this->logger->debug('Failed to update legacy user login timestamp after passkey authentication', [
                    'error' => $exception->getMessage(),
                    'user_id' => $userId,
                ]);
            }
        }

        try {
            $stmt = $this->db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
            $stmt->execute([$timestamp, $userId]);
        } catch (\Throwable $exception) {
            if ($this->logger !== null) {
                $this->logger->debug('Failed to update user login timestamp after passkey authentication', [
                    'error' => $exception->getMessage(),
                    'user_id' => $userId,
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatUserPayload(array $row): array
    {
        $avatar = $this->resolveAvatar($row['avatar_path'] ?? $row['avatar_url'] ?? null);
        $profileFields = $this->userProfileViewService->buildProfileFields($row);

        return [
            'id' => (int) ($row['id'] ?? 0),
            'uuid' => $row['uuid'] ?? null,
            'username' => $row['username'] ?? null,
            'email' => $row['email'] ?? null,
            'school_id' => $profileFields['school_id'],
            'school_name' => $profileFields['school_name'],
            'points' => (int) ($row['points'] ?? 0),
            'is_admin' => (bool) ($row['is_admin'] ?? 0),
            'email_verified_at' => $row['email_verified_at'] ?? null,
            'avatar_id' => $row['avatar_id'] ?? null,
            'avatar_path' => $avatar['avatar_path'],
            'avatar_url' => $avatar['avatar_url'],
            'lastlgn' => $row['lastlgn'] ?? ($row['last_login_at'] ?? null),
            'status' => $row['status'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'region_code' => $profileFields['region_code'],
            'region_label' => $profileFields['region_label'],
            'country_code' => $profileFields['country_code'],
            'state_code' => $profileFields['state_code'],
            'country_name' => $profileFields['country_name'],
            'state_name' => $profileFields['state_name'],
        ];
    }

    /**
     * @return array{avatar_path:?string,avatar_url:?string}
     */
    private function resolveAvatar(?string $filePath): array
    {
        $originalPath = $filePath !== null ? trim($filePath) : null;
        if ($originalPath === '') {
            $originalPath = null;
        }

        $normalized = $originalPath ? ltrim($originalPath, '/') : null;
        $url = ($normalized && $this->r2Service !== null) ? $this->r2Service->getPublicUrl($normalized) : null;

        return [
            'avatar_path' => $originalPath,
            'avatar_url' => $url,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function logPasskeyEvent(
        string $action,
        int $userId,
        string $status,
        array $data,
        string $changeType = 'other',
        string $table = 'user_passkeys'
    ): void {
        $this->auditLogService->log([
            'action' => $action,
            'operation_category' => 'authentication',
            'user_id' => $userId,
            'actor_type' => 'user',
            'affected_table' => $table,
            'status' => $status,
            'change_type' => $changeType,
            'data' => $data,
        ]);
    }

    private function countAuditActionSince(string $action, string $since): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*)
             FROM audit_logs
             WHERE action = :action
               AND status = :status
               AND created_at >= :since'
        );
        $stmt->execute([
            'action' => $action,
            'status' => 'success',
            'since' => $since,
        ]);

        return (int) $stmt->fetchColumn();
    }
}
