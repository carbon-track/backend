<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use CarbonTrack\Controllers\CheckinController;
use CarbonTrack\Models\User;
use CarbonTrack\Models\UserGroup;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\CheckinService;
use CarbonTrack\Services\QuotaService;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Response;

class CheckinControllerTest extends TestCase
{
    private PDO $pdo;
    private string $dbPath;
    private Capsule $capsule;
    private CheckinService $checkinService;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dbPath = tempnam(sys_get_temp_dir(), 'carbontrack_checkins_');
        $this->pdo = new PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        TestSchemaBuilder::init($this->pdo);

        $this->capsule = new Capsule();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => $this->dbPath,
            'prefix' => '',
            'pdo' => $this->pdo,
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        $this->seedUser(1);
        $this->checkinService = new CheckinService($this->pdo, null, 'UTC');
    }

    protected function tearDown(): void
    {
        if (is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        parent::tearDown();
    }

    public function testListCheckinsReturnsCalendarPayload(): void
    {
        $controller = $this->makeController();

        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = $today->modify('-5 days');
        $end = $today;
        $recordDate = $today->modify('-3 days');
        $makeupDate = $today->modify('-2 days');

        $this->checkinService->recordCheckinFromSubmission(
            (int) $this->user->id,
            'rec-1',
            $recordDate
        );
        $this->checkinService->createMakeupCheckin(
            (int) $this->user->id,
            $makeupDate->format('Y-m-d'),
            'missed',
            'rec-makeup-3'
        );

        $request = makeRequest('GET', '/users/me/checkins', null, [
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ]);
        $response = new Response();

        $result = $controller->list($request, $response);
        $this->assertSame(200, $result->getStatusCode());

        $payload = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame(2, count($payload['data']['checkins']));
        $this->assertSame(2, (int) $payload['data']['stats']['total_days']);
        $this->assertSame(1, (int) $payload['data']['makeup_quota']['limit']);
    }

    public function testMakeupCheckinConsumesQuota(): void
    {
        $controller = $this->makeController();

        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $firstDate = $today->modify('-2 days')->format('Y-m-d');
        $secondDate = $today->modify('-1 day')->format('Y-m-d');
        $originalRecordDate = $today->format('Y-m-d');
        $activityId = (string) $this->pdo->query("SELECT id FROM carbon_activities LIMIT 1")->fetchColumn();
        $insertRecord = $this->pdo->prepare("INSERT INTO carbon_records (id, user_id, activity_id, amount, unit, carbon_saved, points_earned, date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'))");
        $insertRecord->execute(['rec-makeup-1', $this->user->id, $activityId, 1, 'km', 0.1, 0, $originalRecordDate]);
        $insertRecord->execute(['rec-makeup-2', $this->user->id, $activityId, 1, 'km', 0.1, 0, $secondDate]);

        $request = makeRequest('POST', '/users/me/checkins/makeup', [
            'date' => $firstDate,
            'note' => 'catch-up',
            'record_id' => 'rec-makeup-1',
        ]);
        $response = new Response();
        $result = $controller->makeup($request, $response);

        $payload = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(200, $result->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertSame($firstDate, $payload['data']['checkin_date']);
        $this->assertSame(1, (int) $payload['data']['makeup_quota']['used']);
        $this->assertSame(0, (int) $payload['data']['makeup_quota']['remaining']);
        $this->assertSame(
            $firstDate,
            $this->pdo->query("SELECT date FROM carbon_records WHERE id = 'rec-makeup-1'")->fetchColumn()
        );
        $audit = $this->pdo->query("SELECT affected_table, old_data, new_data FROM audit_logs WHERE action = 'carbon_record_date_updated_for_makeup'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('carbon_records', $audit['affected_table']);
        $this->assertSame(['date' => $originalRecordDate], json_decode((string) $audit['old_data'], true, 512, JSON_THROW_ON_ERROR));
        $this->assertSame(['date' => $firstDate], json_decode((string) $audit['new_data'], true, 512, JSON_THROW_ON_ERROR));

        $secondRequest = makeRequest('POST', '/users/me/checkins/makeup', [
            'date' => $secondDate,
            'record_id' => 'rec-makeup-2',
        ]);
        $secondResponse = new Response();
        $secondResult = $controller->makeup($secondRequest, $secondResponse);
        $this->assertSame(429, $secondResult->getStatusCode());
    }

    public function testMakeupCheckinCannotMoveReviewedRecord(): void
    {
        $controller = $this->makeController();

        $originalDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-10 days')->format('Y-m-d');
        $targetDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2 days')->format('Y-m-d');
        $activityId = (string) $this->pdo->query("SELECT id FROM carbon_activities LIMIT 1")->fetchColumn();
        $insertRecord = $this->pdo->prepare("INSERT INTO carbon_records (id, user_id, activity_id, amount, unit, carbon_saved, points_earned, date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'approved', datetime('now'))");
        $insertRecord->execute(['rec-makeup-approved', $this->user->id, $activityId, 1, 'km', 0.1, 0, $originalDate]);

        $request = makeRequest('POST', '/users/me/checkins/makeup', [
            'date' => $targetDate,
            'record_id' => 'rec-makeup-approved',
        ]);
        $response = new Response();
        $result = $controller->makeup($request, $response);

        $payload = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(409, $result->getStatusCode());
        $this->assertSame('RECORD_NOT_MUTABLE', $payload['code']);
        $this->assertSame($originalDate, $this->pdo->query("SELECT date FROM carbon_records WHERE id = 'rec-makeup-approved'")->fetchColumn());
        $this->assertFalse(
            $this->pdo->query("SELECT counter FROM user_usage_stats WHERE user_id = " . (int) $this->user->id . " AND resource_key = 'checkin_makeup_monthly'")->fetchColumn()
        );
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM user_checkins WHERE record_id = 'rec-makeup-approved'")->fetchColumn());
    }

    public function testMakeupCheckinAlreadyCheckedInDoesNotConsumeQuota(): void
    {
        $controller = $this->makeController();

        $targetDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-2 days')->format('Y-m-d');
        $activityId = (string) $this->pdo->query("SELECT id FROM carbon_activities LIMIT 1")->fetchColumn();
        $insertRecord = $this->pdo->prepare("INSERT INTO carbon_records (id, user_id, activity_id, amount, unit, carbon_saved, points_earned, date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'))");
        $insertRecord->execute(['rec-makeup-duplicate', $this->user->id, $activityId, 1, 'km', 0.1, 0, $targetDate]);
        $this->checkinService->createMakeupCheckin((int) $this->user->id, $targetDate, 'existing', 'rec-existing');

        $request = makeRequest('POST', '/users/me/checkins/makeup', [
            'date' => $targetDate,
            'record_id' => 'rec-makeup-duplicate',
        ]);
        $response = new Response();
        $result = $controller->makeup($request, $response);

        $this->assertSame(409, $result->getStatusCode());
        $this->assertFalse(
            $this->pdo->query("SELECT counter FROM user_usage_stats WHERE user_id = " . (int) $this->user->id . " AND resource_key = 'checkin_makeup_monthly'")->fetchColumn()
        );
    }

    public function testMakeupCheckinRollsBackQuotaAndRecordDateWhenCheckinWriteFails(): void
    {
        $this->checkinService = new class($this->pdo, null, 'UTC') extends CheckinService {
            public function createMakeupCheckin(
                int $userId,
                string $date,
                ?string $note = null,
                ?string $recordId = null,
                ?\DateTimeInterface $createdAt = null
            ): bool {
                throw new \RuntimeException('simulated checkin write failure');
            }
        };
        $controller = $this->makeController();

        $originalDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $targetDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-3 days')->format('Y-m-d');
        $activityId = (string) $this->pdo->query("SELECT id FROM carbon_activities LIMIT 1")->fetchColumn();
        $insertRecord = $this->pdo->prepare("INSERT INTO carbon_records (id, user_id, activity_id, amount, unit, carbon_saved, points_earned, date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'))");
        $insertRecord->execute(['rec-makeup-fail', $this->user->id, $activityId, 1, 'km', 0.1, 0, $originalDate]);

        $request = makeRequest('POST', '/users/me/checkins/makeup', [
            'date' => $targetDate,
            'record_id' => 'rec-makeup-fail',
        ]);
        $response = new Response();
        $result = $controller->makeup($request, $response);

        $this->assertSame(500, $result->getStatusCode());
        $this->assertSame($originalDate, $this->pdo->query("SELECT date FROM carbon_records WHERE id = 'rec-makeup-fail'")->fetchColumn());
        $this->assertFalse(
            $this->pdo->query("SELECT counter FROM user_usage_stats WHERE user_id = " . (int) $this->user->id . " AND resource_key = 'checkin_makeup_monthly'")->fetchColumn()
        );
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM user_checkins WHERE record_id = 'rec-makeup-fail'")->fetchColumn());
    }

    public function testMakeupCheckinReturnsConflictWhenConcurrentDuplicateWins(): void
    {
        $this->checkinService = new class($this->pdo, null, 'UTC') extends CheckinService {
            private int $hasCheckinCalls = 0;

            public function hasCheckin(int $userId, string $date): bool
            {
                $this->hasCheckinCalls++;
                return false;
            }

            public function getHasCheckinCalls(): int
            {
                return $this->hasCheckinCalls;
            }

            public function createMakeupCheckin(
                int $userId,
                string $date,
                ?string $note = null,
                ?string $recordId = null,
                ?\DateTimeInterface $createdAt = null
            ): bool {
                return false;
            }
        };
        $controller = $this->makeController();

        $originalDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        $targetDate = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-4 days')->format('Y-m-d');
        $activityId = (string) $this->pdo->query("SELECT id FROM carbon_activities LIMIT 1")->fetchColumn();
        $insertRecord = $this->pdo->prepare("INSERT INTO carbon_records (id, user_id, activity_id, amount, unit, carbon_saved, points_earned, date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'))");
        $insertRecord->execute(['rec-makeup-race', $this->user->id, $activityId, 1, 'km', 0.1, 0, $originalDate]);

        $request = makeRequest('POST', '/users/me/checkins/makeup', [
            'date' => $targetDate,
            'record_id' => 'rec-makeup-race',
        ]);
        $response = new Response();
        $result = $controller->makeup($request, $response);

        $this->assertSame(409, $result->getStatusCode());
        $this->assertSame(1, $this->checkinService->getHasCheckinCalls());
        $this->assertSame($originalDate, $this->pdo->query("SELECT date FROM carbon_records WHERE id = 'rec-makeup-race'")->fetchColumn());
        $this->assertFalse(
            $this->pdo->query("SELECT counter FROM user_usage_stats WHERE user_id = " . (int) $this->user->id . " AND resource_key = 'checkin_makeup_monthly'")->fetchColumn()
        );
    }

    private function seedUser(int $monthlyLimit): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $group = UserGroup::create([
            'name' => 'Checkin Group',
            'code' => 'checkin-' . uniqid(),
            'config' => [
                'checkin_makeup' => [
                    'monthly_limit' => $monthlyLimit,
                ],
            ],
            'is_default' => false,
            'notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->user = User::create([
            'username' => 'checkin_user',
            'email' => 'checkin@example.com',
            'status' => 'active',
            'points' => 0,
            'is_admin' => false,
            'group_id' => $group->id,
            'quota_override' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function makeController(): CheckinController
    {
        $logger = new Logger('checkin-test');
        $logger->pushHandler(new NullHandler());

        $user = $this->user;
        $authService = new class($user) extends AuthService {
            private User $user;

            public function __construct(User $user)
            {
            parent::__construct('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', 'HS256', 3600);
                $this->user = $user;
            }

            public function getCurrentUser(Request $request): ?array
            {
                return [
                    'id' => $this->user->id,
                    'username' => $this->user->username,
                    'is_admin' => false,
                ];
            }

            public function getCurrentUserModel(Request $request): ?User
            {
                return $this->user;
            }
        };

        $auditLog = new AuditLogService($this->pdo, $logger);

        return new CheckinController(
            $authService,
            $this->checkinService,
            new QuotaService(),
            $auditLog,
            $logger
        );
    }
}
