<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\FileUploadController;

class FileUploadControllerTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(FileUploadController::class));
    }

    public function testUploadFileUnauthorized(): void
    {
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $auth->method('getCurrentUser')->willReturn(null);
        $controller = new \CarbonTrack\Controllers\FileUploadController($r2, $auth, $audit, $logger);

        $request = makeRequest('POST', '/files/upload');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->uploadFile($request, $response);
        $this->assertEquals(401, $resp->getStatusCode());
    }

    public function testUploadFileMissingFileReturns400(): void
    {
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 1]);
        $controller = new \CarbonTrack\Controllers\FileUploadController($r2, $auth, $audit, $logger);

        $request = makeRequest('POST', '/files/upload', []);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->uploadFile($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testUploadMultipleFilesMissingArrayReturns400(): void
    {
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 2]);
        $controller = new \CarbonTrack\Controllers\FileUploadController($r2, $auth, $audit, $logger);

        $request = makeRequest('POST', '/files/upload-multiple', []);
        $response = new \Slim\Psr7\Response();
        $resp = $controller->uploadMultipleFiles($request, $response);
        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function testDeleteFileNotFoundReturns404(): void
    {
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 3]);
        $r2->method('fileExists')->willReturn(false);
        $controller = new \CarbonTrack\Controllers\FileUploadController($r2, $auth, $audit, $logger);

        $request = makeRequest('DELETE', '/files/delete');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->deleteFile($request, $response, ['path' => 'not-exists.png']);
        $this->assertEquals(404, $resp->getStatusCode());
    }

    public function testGetFileInfoMissingPathReturns400(): void
    {
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $logger = $this->createMock(\Monolog\Logger::class);
        $auth->method('getCurrentUser')->willReturn(['id' => 4]);
        $controller = new \CarbonTrack\Controllers\FileUploadController($r2, $auth, $audit, $logger);

        $request = makeRequest('GET', '/files/info');
        $response = new \Slim\Psr7\Response();
        $resp = $controller->getFileInfo($request, $response, []);
        $this->assertEquals(400, $resp->getStatusCode());
    }
}


