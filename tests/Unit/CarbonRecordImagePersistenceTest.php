<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\CarbonTrackController;
use CarbonTrack\Services\{CarbonCalculatorService, MessageService, AuditLogService, AuthService, ErrorLogService, CloudflareR2Service};
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;
use Slim\Psr7\Response;

final class CarbonRecordImagePersistenceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE carbon_records (id TEXT PRIMARY KEY,user_id INTEGER,activity_id TEXT,amount REAL,unit TEXT,carbon_saved REAL,points_earned INTEGER,date TEXT,description TEXT,images TEXT,status TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,deleted_at TEXT);");
    $this->pdo->exec("CREATE TABLE carbon_activities (id TEXT PRIMARY KEY,name_zh TEXT,name_en TEXT,category TEXT,carbon_factor REAL,unit TEXT,icon TEXT,points_factor REAL DEFAULT 1,description_zh TEXT,description_en TEXT,sort_order INTEGER DEFAULT 0,is_active INTEGER DEFAULT 1,deleted_at TEXT);");
    $this->pdo->exec("INSERT INTO carbon_activities (id,name_zh,name_en,category,carbon_factor,unit,icon) VALUES ('act-1','活动','Activity','daily',1.5,'times','icon-car');");
    // minimal users table for controller queries (notifyAdminsNewRecord & auth mocks)
    $this->pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, is_admin INTEGER DEFAULT 0, school_id INTEGER, points REAL DEFAULT 0, deleted_at TEXT, reset_token TEXT, reset_token_expires_at TEXT, email_verified_at TEXT, verification_code TEXT, verification_token TEXT, verification_code_expires_at TEXT, verification_attempts INTEGER DEFAULT 0, verification_send_count INTEGER DEFAULT 0, verification_last_sent_at TEXT);");
    $this->pdo->exec("INSERT INTO users (id,username,email,is_admin,school_id,points) VALUES (1,'tester','t@example.com',0,1,0);");
    $this->pdo->exec("INSERT INTO users (id,username,email,is_admin,school_id,points) VALUES (2,'admin','admin@example.com',1,1,0);");
    }

    private function makeController(array $uploadResults = []): CarbonTrackController
    {
    $calc = $this->getMockBuilder(CarbonCalculatorService::class)->disableOriginalConstructor()->getMock();
    $msg = $this->getMockBuilder(MessageService::class)->disableOriginalConstructor()->getMock();
    $audit = $this->getMockBuilder(AuditLogService::class)->disableOriginalConstructor()->getMock();
    $auth = $this->getMockBuilder(AuthService::class)->disableOriginalConstructor()->getMock();
    $auth->method('getCurrentUser')->willReturn(['id' => 1, 'username' => 'tester', 'is_admin' => 0]);
    $auth->method('isAdminUser')->willReturn(false);
    $err = $this->getMockBuilder(ErrorLogService::class)->disableOriginalConstructor()->getMock();
    $r2 = $this->getMockBuilder(CloudflareR2Service::class)->disableOriginalConstructor()->getMock();
        if ($uploadResults) {
            $r2->method('uploadMultipleFiles')->willReturn(['results' => $uploadResults]);
            $r2->method('getPublicUrl')->willReturnCallback(fn(string $p) => 'https://cdn.example/' . ltrim($p,'/'));
        }
        return new CarbonTrackController($this->pdo, $calc, $msg, $audit, $auth, $err, $r2);
    }

    public function testStoresImageUrlsFromRequestBody(): void
    {
        $controller = $this->makeController();
        $body = [
            'activity_id' => 'act-1',
            'amount' => 2,
            'date' => '2025-09-01',
            'images' => ['https://a/img1.png','https://b/img2.png']
        ];
        $req = (new ServerRequestFactory())->createServerRequest('POST','/api/v1/carbon-records');
        $req = $req->withParsedBody($body);
    $resp = new Response();
    $out = $controller->submitRecord($req, $resp);
        $raw = (string)$out->getBody();
        $data = json_decode($raw, true);
        if (!isset($data['success'])) {
            fwrite(STDERR, "RAW RESPONSE: $raw\n");
        }
        $this->assertArrayHasKey('success', $data);
        $this->assertTrue($data['success']);
        $recId = $data['data']['record_id'];
        $row = $this->pdo->query("SELECT images FROM carbon_records WHERE id = '$recId'")->fetch(PDO::FETCH_ASSOC);
        $decoded = json_decode($row['images'], true);
        $this->assertCount(2, $decoded);
        $this->assertEquals('https://a/img1.png', $decoded[0]['public_url'] ?? $decoded[0]['url']);
    }

    public function testStoresUploadedImagesFromR2(): void
    {
        $uploadResults = [
            ['success' => true, 'file_path' => 'activities/1/a.jpg', 'public_url' => 'https://cdn.example/activities/1/a.jpg', 'original_name' => 'a.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 1234]
        ];
        $controller = $this->makeController($uploadResults);
    $tmpFile = tempnam(sys_get_temp_dir(), 'upl');
    file_put_contents($tmpFile, 'dummy');
    $uploaded = new UploadedFile($tmpFile, 'a.jpg', 'image/jpeg', filesize($tmpFile), UPLOAD_ERR_OK);
        $req = (new ServerRequestFactory())->createServerRequest('POST','/api/v1/carbon-records');
        $req = $req->withUploadedFiles(['images' => [$uploaded]])->withParsedBody([
            'activity_id' => 'act-1',
            'amount' => 1,
            'date' => '2025-09-01'
        ]);
    $resp = new Response();
    $out = $controller->submitRecord($req, $resp);
    $raw = (string)$out->getBody();
    $data = json_decode($raw, true);
    $this->assertArrayHasKey('success', $data, 'Response missing success key. Raw: ' . $raw);
    $this->assertTrue($data['success']);
        $recId = $data['data']['record_id'];
        $row = $this->pdo->query("SELECT images FROM carbon_records WHERE id = '$recId'")->fetch(PDO::FETCH_ASSOC);
        $decoded = json_decode($row['images'], true);
        $this->assertCount(1, $decoded);
        $this->assertEquals('https://cdn.example/activities/1/a.jpg', $decoded[0]['public_url'] ?? $decoded[0]['url']);
    }

    public function testRejectsWhenNoImagesProvided(): void
    {
        $controller = $this->makeController();
        $req = (new ServerRequestFactory())->createServerRequest('POST','/api/v1/carbon-records');
        $req = $req->withParsedBody([
            'activity_id' => 'act-1',
            'amount' => 5,
            'date' => '2025-09-02'
        ]);
        $resp = new Response();
        $out = $controller->submitRecord($req, $resp);
        $raw = (string)$out->getBody();
        $data = json_decode($raw, true);
        $this->assertArrayNotHasKey('success', $data, 'Should not succeed without images. Raw: ' . $raw);
        $this->assertEquals('Missing required field: images', $data['error'] ?? null, 'Expected images required error');
    }
}
