<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use Illuminate\Database\Connection;
use CarbonTrack\Support\DatabaseRows;
use CarbonTrack\Tests\Support\DatabaseFixture;
use CarbonTrack\Controllers\ProductController;
use CarbonTrack\Models\Message;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\AuthService;
use CarbonTrack\Services\MessageService;
use Monolog\Handler\NullHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;

class ProductExchangeLegacySchemaTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tmp = tempnam(sys_get_temp_dir(), 'carbontrack_legacy_');
        if ($tmp !== false) {
            @unlink($tmp);
            $path = $tmp . '.sqlite';
        } else {
            $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('carbontrack_legacy_', true) . '.sqlite';
        }
        $this->dbPath = $path;
    }

    protected function tearDown(): void
    {
        if (!empty($this->dbPath) && file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }

        parent::tearDown();
    }

    public function testExchangeProductWithLegacySchema(): void
    {
        $connection = DatabaseFixture::sqlite($this->dbPath);

        $this->createLegacySchema($connection);
        $this->seedLegacyData($connection);

        $logger = new Logger('legacy-test');
        $logger->pushHandler(new NullHandler());

        $auditLogMock = $this->createMock(AuditLogService::class);
        $auditLogMock->expects($this->atLeastOnce())->method('log');

        $messageService = new class($logger, $auditLogMock, $connection) extends MessageService {
            private Connection $connection;

            public function __construct(Logger $logger, AuditLogService $auditLogService, Connection $connection)
            {
                parent::__construct($logger, $auditLogService);
                $this->connection = $connection;
            }

            public function sendMessage(
                int $receiverId,
                string $type,
                string $title,
                string $content,
                string $priority = Message::PRIORITY_NORMAL,
                ?int $senderId = null,
                bool $sendEmail = true
            ): Message {
                $now = date('Y-m-d H:i:s');
                $this->connection->statement('INSERT INTO messages (sender_id, receiver_id, title, content, is_read, created_at, updated_at) VALUES (:sender_id, :receiver_id, :title, :content, 0, :now, :now)', [
                    'sender_id' => $senderId,
                    'receiver_id' => $receiverId,
                    'title' => $title,
                    'content' => $content,
                    'now' => $now
                ]);

                $message = new Message();
                $message->receiver_id = $receiverId;
                $message->title = $title;
                $message->content = $content;
                return $message;
            }
        };

        $userPayload = [
            'id' => 1,
            'username' => 'legacy_user',
            'email' => 'legacy@example.com',
            'points' => 500,
            'is_admin' => false
        ];

        $authService = new class('0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef', 'HS256', 3600, $userPayload) extends AuthService {
            private array $mockUser;

            public function __construct($secret, $alg, $exp, array $user)
            {
                parent::__construct($secret, $alg, $exp);
                $this->mockUser = $user;
            }

            public function getCurrentUser(\Psr\Http\Message\ServerRequestInterface $request): ?array
            {
                return $this->mockUser;
            }
        };

        $controller = new ProductController($connection, $messageService, $auditLogMock, $authService);

        $request = makeRequest('POST', '/products/exchange', [
            'product_id' => 1,
            'quantity' => 2,
            'delivery_address' => '测试地址',
            'contact_phone' => '13800000000'
        ]);
        $response = new Response();

        $result = $controller->exchangeProduct($request, $response);

        $this->assertSame(200, $result->getStatusCode(), (string)$result->getBody());
        $payload = json_decode((string)$result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['success']);
        $this->assertSame(200, $payload['points_used']);
        $this->assertArrayHasKey('exchange_id', $payload);

        $exchangeRow = DatabaseRows::one($connection->selectOne('SELECT * FROM point_exchanges WHERE id = :id', ['id' => $payload['exchange_id']]));
        $this->assertNotEmpty($exchangeRow);
        $this->assertSame(1, (int)$exchangeRow['user_id']);
        $this->assertSame(1, (int)$exchangeRow['product_id']);
        $this->assertSame(2, (int)$exchangeRow['quantity']);
        $this->assertSame(200, (int)$exchangeRow['points_used']);
        $this->assertSame('pending', $exchangeRow['status']);

        $pointsTxRow = DatabaseRows::one($connection->selectOne('SELECT * FROM points_transactions ORDER BY id DESC LIMIT 1'));
        $this->assertNotEmpty($pointsTxRow);
        $this->assertSame('legacy@example.com', $pointsTxRow['email']);
        $this->assertSame('legacy_user', $pointsTxRow['username']);
        $this->assertSame('product_exchange', $pointsTxRow['auth']);
        $this->assertSame('spend', $pointsTxRow['type']);
        $this->assertSame('approved', $pointsTxRow['status']);
        $this->assertEquals(-200, (int)$pointsTxRow['points']);
        $this->assertEquals(200, (int)$pointsTxRow['raw']);
        $this->assertNotEmpty($pointsTxRow['time']);

        $updatedPoints = (int)DatabaseRows::scalar($connection->selectOne('SELECT points FROM users WHERE id = 1'));
        $this->assertSame(300, $updatedPoints);

        $messageRow = DatabaseRows::one($connection->selectOne('SELECT * FROM messages ORDER BY id DESC LIMIT 1'));
        $this->assertNotEmpty($messageRow);
        $this->assertSame(1, (int)$messageRow['receiver_id']);
        $this->assertSame('商品兑换成功', $messageRow['title']);
        $this->assertSame(0, (int)$messageRow['is_read']);
    }

    private function createLegacySchema(Connection $connection): void
    {
        $connection->unprepared('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            email TEXT NOT NULL,
            points INTEGER NOT NULL DEFAULT 0,
            is_admin INTEGER NOT NULL DEFAULT 0,
            status TEXT,
            reset_token TEXT,
            reset_token_expires_at TEXT,
            email_verified_at TEXT,
            verification_code TEXT,
            verification_token TEXT,
            verification_code_expires_at TEXT,
            verification_attempts INTEGER NOT NULL DEFAULT 0,
            verification_send_count INTEGER NOT NULL DEFAULT 0,
            verification_last_sent_at TEXT,
            deleted_at TEXT,
            notification_email_mask INTEGER NOT NULL DEFAULT 0
        )');

        $connection->unprepared('CREATE TABLE products (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            description TEXT,
            category TEXT,
            category_slug TEXT,
            images TEXT,
            image_path TEXT,
            stock INTEGER NOT NULL DEFAULT 0,
            points_required INTEGER NOT NULL,
            status TEXT NOT NULL,
            deleted_at TEXT
        )');

        $connection->unprepared('CREATE TABLE point_exchanges (
            id TEXT PRIMARY KEY,
            user_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            points_used INTEGER NOT NULL,
            product_name TEXT,
            product_price INTEGER,
            delivery_address TEXT,
            contact_area_code TEXT,
            contact_phone TEXT,
            notes TEXT,
            status TEXT,
            tracking_number TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $connection->unprepared('CREATE TABLE points_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            email TEXT NOT NULL,
            time TEXT NOT NULL,
            img TEXT,
            points REAL NOT NULL,
            auth TEXT,
            raw REAL NOT NULL,
            act TEXT,
            uid INTEGER NOT NULL,
            activity_id TEXT,
            type TEXT,
            notes TEXT,
            activity_date TEXT,
            status TEXT,
            approved_by INTEGER,
            approved_at TEXT,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');

        $connection->unprepared('CREATE TABLE messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER,
            receiver_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at TEXT,
            updated_at TEXT,
            deleted_at TEXT
        )');
    }

    private function seedLegacyData(Connection $connection): void
    {
        $connection->unprepared("INSERT INTO users (id, username, email, points, is_admin, status) VALUES (1, 'legacy_user', 'legacy@example.com', 500, 0, 'active')");
        $connection->unprepared("INSERT INTO products (id, name, description, category, category_slug, images, image_path, stock, points_required, status) VALUES (
            1,
            '环保水杯',
            '易于携带的环保水杯',
            'daily',
            'daily',
            '[]',
            '/images/products/cup.png',
            10,
            100,
            'active'
        )");
    }
}
