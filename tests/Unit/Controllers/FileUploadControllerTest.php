<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\FileUploadController;
use CarbonTrack\Services\FileMetadataService;
use CarbonTrack\Models\File;

class FileUploadControllerTest extends TestCase
{
    private function controller(?array $user, ?callable $cfg = null, ?FileMetadataService $fileMeta = null): FileUploadController
    {
        $r2 = $this->createMock(\CarbonTrack\Services\CloudflareR2Service::class);
        if ($cfg) { $cfg($r2); }
        $auth = $this->createMock(\CarbonTrack\Services\AuthService::class);
        $auth->method('getCurrentUser')->willReturn($user);
        $audit = $this->createMock(\CarbonTrack\Services\AuditLogService::class);
        $logger = new \Monolog\Logger('test');
        $logger->pushHandler(new \Monolog\Handler\NullHandler());
        $errorLog = $this->createMock(\CarbonTrack\Services\ErrorLogService::class);
        $fileMeta ??= $this->createMock(FileMetadataService::class);
        return new FileUploadController($r2, $auth, $audit, $logger, $errorLog, $fileMeta);
    }

    public function testUnauthorizedUpload(): void
    {
        $c = $this->controller(null);
        $resp = $c->uploadFile(makeRequest('POST','/files/upload'), new \Slim\Psr7\Response());
        $this->assertSame(401, $resp->getStatusCode());
    }

    public function testMissingFileUpload(): void
    {
        $c = $this->controller(['id'=>1]);
        $resp = $c->uploadFile(makeRequest('POST','/files/upload',[]), new \Slim\Psr7\Response());
        $this->assertSame(400, $resp->getStatusCode());
    }

    public function testMultipleMissingArray(): void
    {
        $c = $this->controller(['id'=>2]);
        $resp = $c->uploadMultipleFiles(makeRequest('POST','/files/upload-multiple',[]), new \Slim\Psr7\Response());
        $this->assertSame(400, $resp->getStatusCode());
    }

    public function testDeleteFileNotFound(): void
    {
        $c = $this->controller(['id'=>3], function($r2){ $r2->method('fileExists')->willReturn(false); });
        $resp = $c->deleteFile(makeRequest('DELETE','/files/delete'), new \Slim\Psr7\Response(), ['path'=>'not.png']);
        $this->assertSame(404, $resp->getStatusCode());
    }

    public function testGetInfoMissingPath(): void
    {
        $c = $this->controller(['id'=>4]);
        $resp = $c->getFileInfo(makeRequest('GET','/files/info'), new \Slim\Psr7\Response(), []);
        $this->assertSame(400, $resp->getStatusCode());
    }

    public function testPresignSuccess(): void
    {
        $c = $this->controller(['id'=>10], function($r2){
            $r2->method('getAllowedMimeTypes')->willReturn(['image/jpeg']);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getMaxFileSize')->willReturn(5*1024*1024);
            $r2->method('generateDirectUploadKey')->willReturn([
                'file_name'=>'uuid.jpg','file_path'=>'uploads/x/uuid.jpg','public_url'=>'https://cdn/uuid.jpg'
            ]);
            $r2->method('generateUploadPresignedUrl')->willReturn([
                'url'=>'https://r2/presigned','method'=>'PUT','headers'=>['Content-Type'=>'image/jpeg'],'expires_in'=>600,'expires_at'=>'2025-01-01 00:00:00'
            ]);
        });
        $resp = $c->getDirectUploadPresign(makeRequest('POST','/files/presign',[
            'original_name'=>'a.jpg','mime_type'=>'image/jpeg','file_size'=>123
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
    }

    public function testPresignInvalidSha256(): void
    {
        $c = $this->controller(['id'=>11], function($r2){
            $r2->method('getAllowedMimeTypes')->willReturn(['image/jpeg']);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getMaxFileSize')->willReturn(5*1024*1024);
        });
        $resp = $c->getDirectUploadPresign(makeRequest('POST','/files/presign',[
            'original_name'=>'a.jpg','mime_type'=>'image/jpeg','sha256'=>'BAD'
        ]), new \Slim\Psr7\Response());
        $this->assertSame(400,$resp->getStatusCode());
    }

    public function testPresignDuplicateShortCircuits(): void
    {
    $fileMeta = $this->createMock(FileMetadataService::class);
    $existing = new File();
    $existing->file_path = 'uploads/exist/abc.jpg';
    $existing->reference_count = 3;
    $fileMeta->method('findBySha256')->willReturn($existing);

        $c = $this->controller(['id'=>20], function($r2){
            $r2->method('getAllowedMimeTypes')->willReturn(['image/jpeg']);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getMaxFileSize')->willReturn(5*1024*1024);
        }, $fileMeta);

        $resp = $c->getDirectUploadPresign(makeRequest('POST','/files/presign',[
            'original_name'=>'dup.jpg','mime_type'=>'image/jpeg','sha256'=>str_repeat('a',64)
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($payload['data']['duplicate']);
        $this->assertArrayNotHasKey('url',$payload['data']);
    }

    public function testConfirmCreatesRecord(): void
    {
    $fileMeta = $this->createMock(FileMetadataService::class);
    $fileMeta->method('findBySha256')->willReturn(null);
    $new = new File();
    $new->reference_count = 1;
    $fileMeta->method('createRecord')->willReturn($new);

        $c = $this->controller(['id'=>30], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>'uploads/new.jpg','size'=>10,'mime_type'=>'image/jpeg'
            ]);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST','/files/confirm',[
            'file_path'=>'uploads/new.jpg','original_name'=>'new.jpg','sha256'=>str_repeat('b',64)
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertEquals(1,$payload['data']['reference_count']);
    }

    public function testConfirmDuplicateIncrements(): void
    {
    $existing = new File();
    $existing->file_path='uploads/ok.jpg';
    $existing->reference_count=2;
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->method('findBySha256')->willReturn($existing);
        $fileMeta->method('incrementReference')->willReturnCallback(function($file){
            $file->reference_count +=1; return $file; });

        $c = $this->controller(['id'=>31], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>'uploads/ok.jpg','size'=>10,'mime_type'=>'image/jpeg'
            ]);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST','/files/confirm',[
            'file_path'=>'uploads/ok.jpg','original_name'=>'ok.jpg','sha256'=>str_repeat('c',64)
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($payload['data']['duplicate']);
        $this->assertEquals(3,$payload['data']['reference_count']);
    }

    public function testConfirmNotFound(): void
    {
        $c = $this->controller(['id'=>12], function($r2){
            $r2->method('getFileInfo')->willReturn(null);
        });
        $resp = $c->confirmDirectUpload(makeRequest('POST','/files/confirm',[
            'file_path'=>'uploads/none.jpg','original_name'=>'none.jpg'
        ]), new \Slim\Psr7\Response());
        $this->assertSame(404,$resp->getStatusCode());
    }

    public function testConfirmSuccess(): void
    {
        $c = $this->controller(['id'=>13], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>'uploads/ok.jpg','size'=>1,'mime_type'=>'image/jpeg'
            ]);
            // logDirectUploadAudit is void; no return value expectation needed
        });
        $resp = $c->confirmDirectUpload(makeRequest('POST','/files/confirm',[
            'file_path'=>'uploads/ok.jpg','original_name'=>'ok.jpg'
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
    }
}

