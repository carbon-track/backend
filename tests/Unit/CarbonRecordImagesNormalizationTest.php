<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\CarbonTrackController;
use CarbonTrack\Services\{CarbonCalculatorService, MessageService, AuditLogService, AuthService, ErrorLogService, CloudflareR2Service};
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CarbonRecordImagesNormalizationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE carbon_records (
            id TEXT PRIMARY KEY,
            user_id INTEGER,
            activity_id TEXT,
            amount REAL,
            unit TEXT,
            carbon_saved REAL,
            points_earned INTEGER,
            date TEXT,
            description TEXT,
            images TEXT,
            status TEXT,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT
        );");
        $this->pdo->exec("CREATE TABLE carbon_activities (
            id TEXT PRIMARY KEY,
            name_zh TEXT,
            name_en TEXT,
            category TEXT,
            carbon_factor REAL,
            unit TEXT
        );");
        $this->pdo->exec("INSERT INTO carbon_activities (id,name_zh,name_en,category,carbon_factor,unit) VALUES
            ('act-1','测试活动','Test Activity','daily',1.0,'times');");
    }

    private function makeController(): CarbonTrackController
    {
        // 创建最小可用的依赖 mock/stub
        $calc = $this->createMock(CarbonCalculatorService::class);
        $calc->method('calculate')->willReturn(['carbon_saved' => 1.23, 'points_earned' => 12]);
        $msg = $this->createMock(MessageService::class);
        $audit = $this->createMock(AuditLogService::class);
        $auth = $this->createMock(AuthService::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1, 'username' => 'tester', 'is_admin' => 0]);
        $auth->method('isAdminUser')->willReturn(false);
        $err = $this->createMock(ErrorLogService::class);
        $r2 = $this->createMock(CloudflareR2Service::class);
        $r2->method('getPublicUrl')->willReturnCallback(fn(string $p) => 'https://cdn.example/' . ltrim($p,'/'));

        return new CarbonTrackController($this->pdo, $calc, $msg, $audit, $auth, $err, $r2);
    }

    public function testNormalizeExistingStringArrayImages(): void
    {
        $controller = $this->makeController();
        $imagesJson = json_encode(['https://a/img1.png','https://b/img2.png']);
        $this->pdo->exec("INSERT INTO carbon_records (id,user_id,activity_id,amount,unit,carbon_saved,points_earned,date,description,images,status) VALUES
            ('rec-1',1,'act-1',2,'times',1.23,12,'2025-09-01','desc','$imagesJson','pending');");

        $req = (new ServerRequestFactory())->createServerRequest('GET','/api/v1/carbon-records/rec-1');
        $resp = new Slim\Psr7\Response();
        $ref = new ReflectionClass($controller);
        $method = $ref->getMethod('getRecordDetail');
        $method->setAccessible(true);
        $out = $method->invoke($controller, $req, $resp, ['id' => 'rec-1']);
        $data = json_decode((string)$out->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertCount(2, $data['data']['images']);
        $this->assertArrayHasKey('url', $data['data']['images'][0]);
    }

    public function testNormalizeLegacyObjectWithoutPublicUrl(): void
    {
        $controller = $this->makeController();
        $imagesJson = json_encode([[ 'file_path' => 'activities/2025/09/01/img1.jpg', 'original_name' => 'img1.jpg' ]]);
        $this->pdo->exec("INSERT INTO carbon_records (id,user_id,activity_id,amount,unit,carbon_saved,points_earned,date,description,images,status) VALUES
            ('rec-2',1,'act-1',2,'times',1.23,12,'2025-09-01','desc','$imagesJson','pending');");

        $req = (new ServerRequestFactory())->createServerRequest('GET','/api/v1/carbon-records/rec-2');
        $resp = new Slim\Psr7\Response();
        $ref = new ReflectionClass($controller);
        $method = $ref->getMethod('getRecordDetail');
        $method->setAccessible(true);
        $out = $method->invoke($controller, $req, $resp, ['id' => 'rec-2']);
        $data = json_decode((string)$out->getBody(), true);
        $this->assertTrue($data['success']);
        $this->assertEquals('https://cdn.example/activities/2025/09/01/img1.jpg', $data['data']['images'][0]['url']);
    }
}
