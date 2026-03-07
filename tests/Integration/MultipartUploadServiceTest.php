<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Integration;

use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\MultipartUploadService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class MultipartUploadServiceTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->capsule = new Capsule();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();

        TestSchemaBuilder::init($this->capsule->getConnection()->getPdo());
    }

    public function testRegisterAndClearUploadWriteAuditLogs(): void
    {
        $actions = [];
        $audit = $this->createMock(AuditLogService::class);
        $audit->expects($this->exactly(2))
            ->method('log')
            ->willReturnCallback(function (array $payload) use (&$actions): bool {
                $actions[] = $payload['action'] ?? null;
                return true;
            });

        $service = new MultipartUploadService(new Logger('multipart-test'), $audit, null);

        $upload = $service->registerUpload('upload-123', '/tmp/file.bin', 42, 120);
        $service->clearUpload('upload-123');

        $this->assertSame('upload-123', $upload->upload_id);
        $this->assertContains('multipart_upload_registered', $actions);
        $this->assertContains('multipart_upload_cleared', $actions);
        $this->assertSame(0, (int) $this->capsule->getConnection()->table('multipart_uploads')->count());
    }
}