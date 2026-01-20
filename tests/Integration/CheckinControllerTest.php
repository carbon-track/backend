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
        $activityId = (string) $this->pdo->query("SELECT id FROM carbon_activities LIMIT 1")->fetchColumn();
        $insertRecord = $this->pdo->prepare("INSERT INTO carbon_records (id, user_id, activity_id, amount, unit, carbon_saved, points_earned, date, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', datetime('now'))");
        $insertRecord->execute(['rec-makeup-1', $this->user->id, $activityId, 1, 'km', 0.1, 0, $firstDate]);
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

        $secondRequest = makeRequest('POST', '/users/me/checkins/makeup', [
            'date' => $secondDate,
            'record_id' => 'rec-makeup-2',
        ]);
        $secondResponse = new Response();
        $secondResult = $controller->makeup($secondRequest, $secondResponse);
        $this->assertSame(429, $secondResult->getStatusCode());
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
                parent::__construct('test-secret', 'HS256', 3600);
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

        $auditLog = $this->createMock(AuditLogService::class);

        return new CheckinController(
            $authService,
            $this->checkinService,
            new QuotaService(),
            $auditLog,
            $logger
        );
    }
}
