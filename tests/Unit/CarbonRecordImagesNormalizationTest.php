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
    // Use existing method name from service for consistency
    $calc->method('calculateCarbonReduction')->willReturn(1.23);
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
        $ref = new ReflectionClass($controller);
        $norm = $ref->getMethod('normalizeImages');
        $norm->setAccessible(true);
        $input = ['https://a/img1.png','https://b/img2.png'];
        $out = $norm->invoke($controller, $input);
        $this->assertCount(2, $out);
        $this->assertArrayHasKey('url', $out[0]);
        $this->assertEquals('https://a/img1.png', $out[0]['url']);
    }

    public function testNormalizeLegacyObjectWithoutPublicUrl(): void
    {
        $controller = $this->makeController();
        $ref = new ReflectionClass($controller);
        $norm = $ref->getMethod('normalizeImages');
        $norm->setAccessible(true);
        $input = [[ 'file_path' => 'activities/2025/09/01/img1.jpg', 'original_name' => 'img1.jpg' ]];
        $out = $norm->invoke($controller, $input);
        $this->assertCount(1, $out);
        $this->assertStringContainsString('activities/2025/09/01/img1.jpg', $out[0]['url']);
        $this->assertEquals('img1.jpg', $out[0]['original_name']);
    }
}
