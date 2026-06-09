<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit\Controllers;

use PHPUnit\Framework\TestCase;
use CarbonTrack\Controllers\FileUploadController;
use CarbonTrack\Services\FileMetadataService;
use CarbonTrack\Services\FileOwnershipConflictException;
use CarbonTrack\Services\MultipartUploadService;
use CarbonTrack\Models\File;
use CarbonTrack\Models\MultipartUpload;

class FileUploadControllerTest extends TestCase
{
    private const MIME_JPEG = 'image/jpeg';
    private const MIME_PNG = 'image/png';
    private const ROUTE_PRESIGN = '/files/presign';
    private const ROUTE_CONFIRM = '/files/confirm';
    private const ROUTE_MULTIPART_COMPLETE = '/files/multipart/complete';
    private const PRIVATE_ACTIVITY_PATH = 'activities/2026/03/proof.jpg';
    private const PRIVATE_UPLOAD_PATH = 'uploads/2026/03/doc.png';
    private const PRIVATE_UPLOAD_ENCODED_PATH = 'uploads%2F2026%2F03%2Fdoc.png';
    private const NEW_UPLOAD_PATH = 'uploads/new.jpg';
    private const OWNERLESS_UPLOAD_PATH = 'uploads/existing-ownerless.jpg';
    private const FOREIGN_DUPLICATE_PATH = 'uploads/foreign-owned.jpg';
    private const MULTIPART_FILE_PATH = 'uploads/2026/03/big.jpg';
    private const MULTIPART_EXISTING_FILE_PATH = 'uploads/2026/03/existing-big.jpg';
    private const MULTIPART_SHA256 = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private const EXISTING_OK_PATH = 'uploads/ok.jpg';
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->temporaryFiles = [];
        parent::tearDown();
    }

    private function controller(?array $user, ?callable $cfg = null, ?FileMetadataService $fileMeta = null, ?MultipartUploadService $multipart = null): FileUploadController
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
        $multipart ??= $this->createMock(MultipartUploadService::class);
        return new FileUploadController($r2, $auth, $audit, $logger, $errorLog, $fileMeta, $multipart);
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

    public function testUploadFileRejectsAdminOnlyDirectoryForNonAdmin(): void
    {
        $c = $this->controller(['id' => 5, 'is_admin' => 0], function($r2) {
            $r2->expects($this->never())->method('uploadFile');
        });
        $request = makeRequest('POST', '/files/upload', ['directory' => 'products'])
            ->withUploadedFiles(['file' => $this->makeUploadedFile('item.jpg')]);

        $resp = $c->uploadFile($request, new \Slim\Psr7\Response());

        $this->assertSame(403, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('ADMIN_UPLOAD_DIRECTORY_REQUIRED', $payload['code']);
    }

    public function testUploadMultipleRejectsAdminOnlyDirectoryForNonAdmin(): void
    {
        $c = $this->controller(['id' => 6, 'is_admin' => 0], function($r2) {
            $r2->expects($this->never())->method('uploadMultipleFiles');
        });
        $request = makeRequest('POST', '/files/upload-multiple', ['directory' => 'badges'])
            ->withUploadedFiles(['files' => [$this->makeUploadedFile('badge.jpg')]]);

        $resp = $c->uploadMultipleFiles($request, new \Slim\Psr7\Response());

        $this->assertSame(403, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('ADMIN_UPLOAD_DIRECTORY_REQUIRED', $payload['code']);
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

    public function testGetPrivateInfoDeniedForNonOwner(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->method('isPubliclyReadablePath')->with(self::PRIVATE_ACTIVITY_PATH)->willReturn(false);
        $fileMeta->method('findByFilePath')->with(self::PRIVATE_ACTIVITY_PATH)->willReturn(null);

        $c = $this->controller(['id' => 4], function($r2) {
            $r2->method('getFileInfo')->willReturn([
                'file_path' => self::PRIVATE_ACTIVITY_PATH,
                'metadata' => ['uploaded_by' => '7']
            ]);
        }, $fileMeta);

        $resp = $c->getFileInfo(makeRequest('GET', '/files/activities/info'), new \Slim\Psr7\Response(), ['path' => 'activities%2F2026%2F03%2Fproof.jpg']);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testGetPublicInfoAllowedForAnyAuthenticatedUser(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->method('isPubliclyReadablePath')->with('products/2026/03/item.jpg')->willReturn(true);

        $c = $this->controller(['id' => 9], function($r2) {
            $r2->method('getFileInfo')->willReturn([
                'file_path' => 'products/2026/03/item.jpg',
                'metadata' => []
            ]);
        }, $fileMeta);

        $resp = $c->getFileInfo(makeRequest('GET', '/files/products/info'), new \Slim\Psr7\Response(), ['path' => 'products%2F2026%2F03%2Fitem.jpg']);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testDeletePublicFileDeniedForNonOwner(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->method('findByFilePath')->with('avatars/animals/cat.png')->willReturn(null);

        $c = $this->controller(['id' => 2], function($r2) {
            $r2->method('getFileInfo')->willReturn([
                'file_path' => 'avatars/animals/cat.png',
                'metadata' => ['uploaded_by' => '88']
            ]);
        }, $fileMeta);

        $resp = $c->deleteFile(makeRequest('DELETE', '/files/avatars/cat.png'), new \Slim\Psr7\Response(), ['path' => 'avatars%2Fanimals%2Fcat.png']);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testPresignedUrlDeniedForPrivateFileNonOwner(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->method('isPubliclyReadablePath')->with(self::PRIVATE_UPLOAD_PATH)->willReturn(false);
        $fileMeta->method('findByFilePath')->with(self::PRIVATE_UPLOAD_PATH)->willReturn(null);

        $c = $this->controller(['id' => 3], function($r2) {
            $r2->method('getFileInfo')->willReturn([
                'file_path' => self::PRIVATE_UPLOAD_PATH,
                'metadata' => ['uploaded_by' => '4']
            ]);
        }, $fileMeta);

        $resp = $c->generatePresignedUrl(makeRequest('GET', '/files/uploads/presigned-url'), new \Slim\Psr7\Response(), ['path' => self::PRIVATE_UPLOAD_ENCODED_PATH]);
        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testPresignSuccess(): void
    {
        $c = $this->controller(['id'=>10], function($r2){
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_JPEG]);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getMaxFileSize')->willReturn(5*1024*1024);
            $r2->method('generateDirectUploadKey')->willReturn([
                'file_name'=>'uuid.jpg','file_path'=>'uploads/x/uuid.jpg','public_url'=>'https://cdn/uuid.jpg'
            ]);
            $r2->expects($this->once())
                ->method('generateUploadPresignedUrl')
                ->with(
                    'uploads/x/uuid.jpg',
                    self::MIME_JPEG,
                    600,
                    $this->callback(function(array $metadata): bool {
                        return !array_key_exists('original_name', $metadata)
                            && ($metadata['uploaded_by'] ?? null) === '10'
                            && ($metadata['entity_type'] ?? null) === 'unknown'
                            && array_key_exists('upload_time', $metadata);
                    })
                )
                ->willReturn([
                    'url'=>'https://r2/presigned',
                    'method'=>'PUT',
                    'headers'=>[
                        'Content-Type'=>self::MIME_JPEG,
                        'x-amz-meta-uploaded_by'=>'10'
                    ],
                    'expires_in'=>600,
                    'expires_at'=>'2025-01-01 00:00:00'
                ]);
        });
        $resp = $c->getDirectUploadPresign(makeRequest('POST', self::ROUTE_PRESIGN,[
            'original_name'=>'a.jpg','mime_type'=>self::MIME_JPEG,'file_size'=>123
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertSame('10', $payload['data']['headers']['x-amz-meta-uploaded_by']);
        $this->assertArrayNotHasKey('x-amz-meta-original_name', $payload['data']['headers']);
    }

    public function testPresignInvalidSha256(): void
    {
        $c = $this->controller(['id'=>11], function($r2){
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_JPEG]);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getMaxFileSize')->willReturn(5*1024*1024);
        });
        $resp = $c->getDirectUploadPresign(makeRequest('POST', self::ROUTE_PRESIGN,[
            'original_name'=>'a.jpg','mime_type'=>self::MIME_JPEG,'sha256'=>'BAD'
        ]), new \Slim\Psr7\Response());
        $this->assertSame(400,$resp->getStatusCode());
    }

    public function testPresignDuplicateShortCircuits(): void
    {
    $fileMeta = $this->createMock(FileMetadataService::class);
    $existing = new File();
    $existing->file_path = 'uploads/exist/abc.jpg';
    $existing->user_id = 20;
    $existing->reference_count = 3;
    $fileMeta->method('findBySha256')->willReturn($existing);

        $c = $this->controller(['id'=>20], function($r2){
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_JPEG]);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getMaxFileSize')->willReturn(5*1024*1024);
        }, $fileMeta);

        $resp = $c->getDirectUploadPresign(makeRequest('POST', self::ROUTE_PRESIGN,[
            'original_name'=>'dup.jpg','mime_type'=>self::MIME_JPEG,'sha256'=>str_repeat('a',64)
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($payload['data']['duplicate']);
        $this->assertArrayNotHasKey('url',$payload['data']);
    }

    public function testPresignDuplicateOwnedByAnotherUserDoesNotShortCircuit(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $existing = new File();
        $existing->file_path = 'uploads/exist/abc.jpg';
        $existing->user_id = 99;
        $existing->reference_count = 3;
        $fileMeta->method('findBySha256')->willReturn($existing);

        $c = $this->controller(['id' => 20], function($r2) {
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_JPEG]);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getMaxFileSize')->willReturn(5 * 1024 * 1024);
            $r2->method('generateDirectUploadKey')->willReturn([
                'file_name' => 'uuid.jpg',
                'file_path' => self::FOREIGN_DUPLICATE_PATH,
                'public_url' => 'https://cdn/foreign-owned.jpg'
            ]);
            $r2->expects($this->once())
                ->method('generateUploadPresignedUrl')
                ->willReturn([
                    'url' => 'https://r2/presigned',
                    'method' => 'PUT',
                    'headers' => ['Content-Type' => self::MIME_JPEG],
                    'expires_in' => 600,
                    'expires_at' => '2026-03-10 00:00:00'
                ]);
        }, $fileMeta);

        $resp = $c->getDirectUploadPresign(makeRequest('POST', self::ROUTE_PRESIGN, [
            'original_name' => 'dup.jpg',
            'mime_type' => self::MIME_JPEG,
            'sha256' => str_repeat('a', 64)
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($payload['data']['duplicate']);
        $this->assertSame(self::FOREIGN_DUPLICATE_PATH, $payload['data']['file_path']);
        $this->assertArrayHasKey('url', $payload['data']);
    }

    public function testPresignAllowsNestedAvatarDirectory(): void
    {
        $c = $this->controller(['id'=>21], function($r2){
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_PNG]);
            $r2->method('getAllowedExtensions')->willReturn(['png']);
            $r2->method('getMaxFileSize')->willReturn(5*1024*1024);
            $r2->expects($this->once())
                ->method('generateDirectUploadKey')
                ->with('face.png', 'avatars/custom-set')
                ->willReturn([
                    'file_name'=>'uuid.png',
                    'file_path'=>'avatars/custom-set/2024/12/uuid.png',
                    'public_url'=>'https://cdn/uuid.png'
                ]);
            $r2->expects($this->once())
                ->method('generateUploadPresignedUrl')
                ->with(
                    'avatars/custom-set/2024/12/uuid.png',
                    self::MIME_PNG,
                    600,
                    $this->callback(function(array $metadata): bool {
                        return !array_key_exists('original_name', $metadata)
                            && ($metadata['uploaded_by'] ?? null) === '21';
                    })
                )
                ->willReturn([
                    'url'=>'https://r2/presigned',
                    'method'=>'PUT',
                    'headers'=>['Content-Type'=>self::MIME_PNG],
                    'expires_in'=>600,
                    'expires_at'=>'2025-01-01 00:00:00'
                ]);
        });

        $resp = $c->getDirectUploadPresign(makeRequest('POST', self::ROUTE_PRESIGN,[
            'original_name'=>'face.png',
            'mime_type'=>self::MIME_PNG,
            'file_size'=>512,
            'directory'=>'avatars/custom-set'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testPresignAllowsSupportTicketsDirectory(): void
    {
        $c = $this->controller(['id' => 24], function($r2) {
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_JPEG]);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getMaxFileSize')->willReturn(5 * 1024 * 1024);
            $r2->expects($this->once())
                ->method('generateDirectUploadKey')
                ->with('ticket.jpg', 'support-tickets')
                ->willReturn([
                    'file_name' => 'uuid.jpg',
                    'file_path' => 'support-tickets/2026/04/uuid.jpg',
                    'public_url' => 'https://cdn/uuid.jpg'
                ]);
            $r2->expects($this->once())
                ->method('generateUploadPresignedUrl')
                ->willReturn([
                    'url' => 'https://r2/presigned',
                    'method' => 'PUT',
                    'headers' => ['Content-Type' => self::MIME_JPEG],
                    'expires_in' => 600,
                    'expires_at' => '2026-04-06 00:00:00'
                ]);
        });

        $resp = $c->getDirectUploadPresign(makeRequest('POST', self::ROUTE_PRESIGN, [
            'original_name' => 'ticket.jpg',
            'mime_type' => self::MIME_JPEG,
            'file_size' => 26570,
            'directory' => 'support-tickets',
            'entity_type' => 'support_ticket_message'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testPresignRejectsAdminOnlyDirectoryForNonAdmin(): void
    {
        $c = $this->controller(['id' => 25, 'is_admin' => 0], function($r2) {
            $r2->expects($this->never())->method('generateDirectUploadKey');
            $r2->expects($this->never())->method('generateUploadPresignedUrl');
        });

        $resp = $c->getDirectUploadPresign(makeRequest('POST', self::ROUTE_PRESIGN, [
            'original_name' => 'item.jpg',
            'mime_type' => self::MIME_JPEG,
            'file_size' => 1024,
            'directory' => 'products',
        ]), new \Slim\Psr7\Response());

        $this->assertSame(403, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('ADMIN_UPLOAD_DIRECTORY_REQUIRED', $payload['code']);
    }

    public function testPresignAllowsAdminOnlyDirectoryForAdmin(): void
    {
        $c = $this->controller(['id' => 26, 'is_admin' => 1], function($r2) {
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_JPEG]);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getMaxFileSize')->willReturn(5 * 1024 * 1024);
            $r2->expects($this->once())
                ->method('generateDirectUploadKey')
                ->with('item.jpg', 'products')
                ->willReturn([
                    'file_name' => 'uuid.jpg',
                    'file_path' => 'products/2026/05/uuid.jpg',
                    'public_url' => 'https://cdn/uuid.jpg',
                ]);
            $r2->expects($this->once())
                ->method('generateUploadPresignedUrl')
                ->willReturn([
                    'url' => 'https://r2/presigned',
                    'method' => 'PUT',
                    'headers' => ['Content-Type' => self::MIME_JPEG],
                    'expires_in' => 600,
                    'expires_at' => '2026-05-23 00:00:00',
                ]);
        });

        $resp = $c->getDirectUploadPresign(makeRequest('POST', self::ROUTE_PRESIGN, [
            'original_name' => 'item.jpg',
            'mime_type' => self::MIME_JPEG,
            'file_size' => 1024,
            'directory' => 'products',
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testPresignUnicodeFileNameDoesNotLeakIntoSignedHeaders(): void
    {
        $c = $this->controller(['id' => 23], function($r2) {
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_JPEG]);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getMaxFileSize')->willReturn(5 * 1024 * 1024);
            $r2->method('generateDirectUploadKey')->with('微信图片.jpg', 'uploads')->willReturn([
                'file_name' => 'uuid.jpg',
                'file_path' => 'uploads/2026/03/uuid.jpg',
                'public_url' => 'https://cdn/uuid.jpg'
            ]);
            $r2->expects($this->once())
                ->method('generateUploadPresignedUrl')
                ->with(
                    'uploads/2026/03/uuid.jpg',
                    self::MIME_JPEG,
                    600,
                    $this->callback(function(array $metadata): bool {
                        return !array_key_exists('original_name', $metadata)
                            && ($metadata['uploaded_by'] ?? null) === '23';
                    })
                )
                ->willReturn([
                    'url' => 'https://r2/presigned',
                    'method' => 'PUT',
                    'headers' => [
                        'Content-Type' => self::MIME_JPEG,
                        'x-amz-meta-uploaded_by' => '23'
                    ],
                    'expires_in' => 600,
                    'expires_at' => '2026-03-10 00:00:00'
                ]);
        });

        $resp = $c->getDirectUploadPresign(makeRequest('POST', self::ROUTE_PRESIGN, [
            'original_name' => '微信图片.jpg',
            'mime_type' => self::MIME_JPEG,
            'file_size' => 2048
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertArrayNotHasKey('x-amz-meta-original_name', $payload['data']['headers']);
    }

    public function testPresignRejectsInvalidDirectory(): void
    {
        $c = $this->controller(['id'=>22], function($r2){
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_PNG]);
            $r2->method('getAllowedExtensions')->willReturn(['png']);
            $r2->method('getMaxFileSize')->willReturn(5*1024*1024);
        });

        $resp = $c->getDirectUploadPresign(makeRequest('POST', self::ROUTE_PRESIGN,[
            'original_name'=>'bad.png',
            'mime_type'=>self::MIME_PNG,
            'file_size'=>256,
            'directory'=>'avatars/../../etc/passwd'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(400, $resp->getStatusCode());
    }

    public function testConfirmCreatesRecord(): void
    {
    $fileMeta = $this->createMock(FileMetadataService::class);
    $fileMeta->method('findByFilePath')->with(self::NEW_UPLOAD_PATH)->willReturn(null);
    $fileMeta->method('findBySha256')->willReturn(null);
    $new = new File();
    $new->reference_count = 1;
    $fileMeta->method('createRecord')->willReturn($new);

        $c = $this->controller(['id'=>30], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::NEW_UPLOAD_PATH,'size'=>10,'mime_type'=>'image/jpeg',
                'metadata'=>['uploaded_by'=>'30']
            ]);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::NEW_UPLOAD_PATH,'original_name'=>'new.jpg','sha256'=>str_repeat('b',64)
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertEquals(1,$payload['data']['reference_count']);
    }

    public function testConfirmRejectsNewObjectWithoutMatchingUploadedByMetadata(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::NEW_UPLOAD_PATH)
            ->willReturn(null);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id' => 30], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path' => self::NEW_UPLOAD_PATH,
                'size' => 10,
                'mime_type' => 'image/jpeg',
                'metadata' => [],
            ]);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM, [
            'file_path' => self::NEW_UPLOAD_PATH,
            'original_name' => 'new.jpg',
            'sha256' => str_repeat('b', 64),
        ]), new \Slim\Psr7\Response());

        $this->assertSame(409, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('FILE_OWNERSHIP_CONFLICT', $payload['code']);
    }

    public function testConfirmRejectsAdminOnlyPathForNonAdminBeforeReadingStorage(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id' => 30, 'is_admin' => 0], function($r2) {
            $r2->expects($this->never())->method('getFileInfo');
            $r2->expects($this->never())->method('validateDirectUploadObject');
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM, [
            'file_path' => 'products/2026/05/item.jpg',
            'original_name' => 'item.jpg',
            'sha256' => str_repeat('b', 64),
        ]), new \Slim\Psr7\Response());

        $this->assertSame(403, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('ADMIN_UPLOAD_DIRECTORY_REQUIRED', $payload['code']);
    }

    public function testConfirmRejectsBucketPrefixedAdminOnlyPathForNonAdmin(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id' => 30, 'is_admin' => 0], function($r2) {
            $r2->method('getBucketName')->willReturn('CarbonTrack-Images');
            $r2->expects($this->never())->method('getFileInfo');
            $r2->expects($this->never())->method('validateDirectUploadObject');
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM, [
            'file_path' => 'carbontrack-images/products/2026/05/item.jpg',
            'original_name' => 'item.jpg',
            'sha256' => str_repeat('b', 64),
        ]), new \Slim\Psr7\Response());

        $this->assertSame(403, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('ADMIN_UPLOAD_DIRECTORY_REQUIRED', $payload['code']);
    }

    public function testConfirmRejectsBucketPrefixedAdminOnlyPathWithEmptySegmentsForNonAdmin(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id' => 30, 'is_admin' => 0], function($r2) {
            $r2->method('getBucketName')->willReturn('CarbonTrack-Images');
            $r2->expects($this->never())->method('getFileInfo');
            $r2->expects($this->never())->method('validateDirectUploadObject');
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM, [
            'file_path' => 'carbontrack-images//products/2026/05/item.jpg',
            'original_name' => 'item.jpg',
            'sha256' => str_repeat('b', 64),
        ]), new \Slim\Psr7\Response());

        $this->assertSame(403, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('ADMIN_UPLOAD_DIRECTORY_REQUIRED', $payload['code']);
    }

    public function testConfirmRejectsBucketPrefixedAdminOnlyRootForNonAdmin(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id' => 30, 'is_admin' => 0], function($r2) {
            $r2->method('getBucketName')->willReturn('CarbonTrack-Images');
            $r2->expects($this->never())->method('getFileInfo');
            $r2->expects($this->never())->method('validateDirectUploadObject');
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM, [
            'file_path' => 'carbontrack-images/products',
            'original_name' => 'item.jpg',
            'sha256' => str_repeat('b', 64),
        ]), new \Slim\Psr7\Response());

        $this->assertSame(403, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('ADMIN_UPLOAD_DIRECTORY_REQUIRED', $payload['code']);
    }

    public function testConfirmRejectsTraversalBeforeAdminRootForNonAdmin(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id' => 30, 'is_admin' => 0], function($r2) {
            $r2->expects($this->never())->method('getFileInfo');
            $r2->expects($this->never())->method('validateDirectUploadObject');
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM, [
            'file_path' => 'shared/../products/item.jpg',
            'original_name' => 'item.jpg',
            'sha256' => str_repeat('b', 64),
        ]), new \Slim\Psr7\Response());

        $this->assertSame(403, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('ADMIN_UPLOAD_DIRECTORY_REQUIRED', $payload['code']);
    }

    public function testConfirmAllowsOwnedExistingRecordWhenObjectMetadataIsMissing(): void
    {
        $existing = $this->getMockBuilder(File::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $existing->file_path = self::OWNERLESS_UPLOAD_PATH;
        $existing->user_id = 14;
        $existing->mime_type = null;
        $existing->size = 0;
        $existing->original_name = null;
        $existing->reference_count = 1;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::OWNERLESS_UPLOAD_PATH)
            ->willReturn($existing);

        $existing->expects($this->once())
            ->method('save');

        $c = $this->controller(['id'=>14], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::OWNERLESS_UPLOAD_PATH,
                'size'=>2048,
                'mime_type'=>self::MIME_JPEG,
                'metadata'=>[]
            ]);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::OWNERLESS_UPLOAD_PATH,'original_name'=>'ownerless.jpg'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200,$resp->getStatusCode());
        $this->assertSame(14, $existing->user_id);
    }

    public function testConfirmDuplicateIncrements(): void
    {
    $existing = new File();
    $existing->file_path=self::EXISTING_OK_PATH;
    $existing->user_id = 31;
    $existing->reference_count=2;
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->method('findByFilePath')->with(self::EXISTING_OK_PATH)->willReturn(null);
        $fileMeta->method('findBySha256')->willReturn($existing);
        $fileMeta->method('incrementReference')->willReturnCallback(function($file) {
            $file->reference_count += 1;
            return $file;
        });

        $c = $this->controller(['id'=>31], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::EXISTING_OK_PATH,'size'=>10,'mime_type'=>self::MIME_JPEG,
                'metadata'=>['uploaded_by'=>'31']
            ]);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::EXISTING_OK_PATH,'original_name'=>'ok.jpg','sha256'=>str_repeat('c',64)
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertTrue($payload['data']['duplicate']);
        $this->assertEquals(3,$payload['data']['reference_count']);
    }

    public function testConfirmCrossOwnerSha256CreatesIndependentRecordWithoutPersistingHash(): void
    {
        $existing = new File();
        $existing->file_path = self::EXISTING_OK_PATH;
        $existing->user_id = 99;
        $existing->reference_count = 2;

        $created = new File();
        $created->reference_count = 1;
        $created->sha256 = hash('sha256', json_encode([
            'file_path' => self::FOREIGN_DUPLICATE_PATH,
            'etag' => '',
            'size' => 10,
            'mime_type' => self::MIME_JPEG,
            'original_name' => 'foreign-owned.jpg',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::FOREIGN_DUPLICATE_PATH)
            ->willReturn(null);
        $fileMeta->expects($this->once())
            ->method('findBySha256')
            ->with(str_repeat('e', 64))
            ->willReturn($existing);
        $fileMeta->expects($this->once())
            ->method('createRecord')
            ->with($this->callback(function(array $data): bool {
                return ($data['file_path'] ?? null) === self::FOREIGN_DUPLICATE_PATH
                    && ($data['user_id'] ?? null) === 32
                    && is_string($data['sha256'] ?? null)
                    && preg_match('/^[a-f0-9]{64}$/', $data['sha256']) === 1
                    && ($data['sha256'] ?? null) !== str_repeat('e', 64);
            }))
            ->willReturn($created);

        $c = $this->controller(['id' => 32], function($r2) {
            $r2->method('getFileInfo')->willReturn([
                'file_path' => self::FOREIGN_DUPLICATE_PATH,
                'size' => 10,
                'mime_type' => self::MIME_JPEG,
                'metadata' => [
                    'uploaded_by' => '32',
                    'sha256' => str_repeat('e', 64),
                ],
            ]);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM, [
            'file_path' => self::FOREIGN_DUPLICATE_PATH,
            'original_name' => 'foreign-owned.jpg',
            'sha256' => str_repeat('e', 64)
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertFalse($payload['data']['duplicate']);
        $this->assertSame($created->sha256, $payload['data']['sha256']);
        $this->assertSame(1, $payload['data']['reference_count']);
    }

    public function testConfirmPersistsResolvedBucketPrefixedStoragePath(): void
    {
        $requestedPath = 'uploads/prefix-fallback.jpg';
        $actualPath = 'carbontrack-bucket/' . $requestedPath;

        $created = new File();
        $created->reference_count = 1;
        $created->sha256 = str_repeat('a', 64);

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with($actualPath)
            ->willReturn(null);
        $fileMeta->expects($this->once())
            ->method('createRecord')
            ->with($this->callback(function(array $data) use ($actualPath): bool {
                return ($data['file_path'] ?? null) === $actualPath
                    && ($data['user_id'] ?? null) === 41
                    && is_string($data['sha256'] ?? null);
            }))
            ->willReturn($created);

        $c = $this->controller(['id'=>41], function($r2) use ($requestedPath, $actualPath) {
            $r2->method('getBucketName')->willReturn('carbontrack-bucket');
            $r2->method('getFileInfo')->willReturnCallback(function($path) use ($actualPath) {
                if ($path !== $actualPath) {
                    return null;
                }

                return [
                    'file_path' => $actualPath,
                    'size' => 10,
                    'mime_type' => self::MIME_JPEG,
                    'metadata' => ['uploaded_by' => '41'],
                ];
            });
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with($actualPath, 'prefix-fallback.jpg', $this->isType('array'));
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM, [
            'file_path' => $requestedPath,
            'original_name' => 'prefix-fallback.jpg',
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame($actualPath, $payload['data']['file_path']);
    }

    public function testConfirmNotFound(): void
    {
        $c = $this->controller(['id'=>12], function($r2){
            $r2->method('getFileInfo')->willReturn(null);
        });
        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>'uploads/none.jpg','original_name'=>'none.jpg'
        ]), new \Slim\Psr7\Response());
        $this->assertSame(404,$resp->getStatusCode());
    }

    public function testConfirmSuccess(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::EXISTING_OK_PATH)
            ->willReturn(null);
        $fileMeta->expects($this->once())
            ->method('createRecord')
            ->with($this->callback(function(array $data): bool {
                return ($data['file_path'] ?? null) === self::EXISTING_OK_PATH
                    && ($data['user_id'] ?? null) === 13
                    && ($data['original_name'] ?? null) === 'ok.jpg'
                    && is_string($data['sha256'] ?? null)
                    && preg_match('/^[a-f0-9]{64}$/', $data['sha256']) === 1;
            }))
            ->willReturn((function() {
                $file = new File();
                $file->reference_count = 1;
                $file->sha256 = str_repeat('f', 64);
                return $file;
            })());

        $c = $this->controller(['id'=>13], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::EXISTING_OK_PATH,'size'=>1,'mime_type'=>self::MIME_JPEG,
                'metadata'=>['uploaded_by'=>'13']
            ]);
            // logDirectUploadAudit is void; no return value expectation needed
        }, $fileMeta);
        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::EXISTING_OK_PATH,'original_name'=>'ok.jpg'
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
    }

    public function testConfirmDeletesObjectAndRejectsWhenContentValidationFails(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id'=>33], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::EXISTING_OK_PATH,
                'size'=>256,
                'mime_type'=>self::MIME_PNG,
                'metadata'=>['uploaded_by'=>'33'],
            ]);
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::EXISTING_OK_PATH, 'ok.png', $this->isType('array'))
                ->willThrowException(new \InvalidArgumentException('File content does not match the declared MIME type'));
            $r2->expects($this->once())
                ->method('deleteFile')
                ->with(self::EXISTING_OK_PATH, 33)
                ->willReturn(true);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::EXISTING_OK_PATH,'original_name'=>'ok.png'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(400, $resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertSame('INVALID_FILE_CONTENT', $payload['code']);
    }

    public function testConfirmStillReturnsInvalidContentWhenFailedCleanupThrows(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id'=>33], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::EXISTING_OK_PATH,
                'size'=>256,
                'mime_type'=>self::MIME_PNG,
                'metadata'=>['uploaded_by'=>'33'],
            ]);
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::EXISTING_OK_PATH, 'ok.png', $this->isType('array'))
                ->willThrowException(new \InvalidArgumentException('File content does not match the declared MIME type'));
            $r2->expects($this->once())
                ->method('deleteFile')
                ->with(self::EXISTING_OK_PATH, 33)
                ->willThrowException(new \RuntimeException('cleanup unavailable'));
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::EXISTING_OK_PATH,'original_name'=>'ok.png'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(400, $resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertSame('INVALID_FILE_CONTENT', $payload['code']);
    }

    public function testConfirmDoesNotDeleteObjectWhenContentVerificationFailsUnexpectedly(): void
    {
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id'=>33], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::EXISTING_OK_PATH,
                'size'=>256,
                'mime_type'=>self::MIME_PNG,
                'metadata'=>['uploaded_by'=>'33'],
            ]);
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::EXISTING_OK_PATH, 'ok.png', $this->isType('array'))
                ->willThrowException(new \RuntimeException('R2 validation unavailable'));
            $r2->expects($this->never())->method('deleteFile');
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::EXISTING_OK_PATH,'original_name'=>'ok.png'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(500, $resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertSame('FILE_CONTENT_VALIDATION_ERROR', $payload['code']);
    }

    public function testConfirmDoesNotDeleteUnownedObjectWhenContentValidationFails(): void
    {
        $foreign = new File();
        $foreign->user_id = 44;
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::EXISTING_OK_PATH)
            ->willReturn($foreign);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id'=>33], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::EXISTING_OK_PATH,
                'size'=>256,
                'mime_type'=>self::MIME_PNG,
                'metadata'=>['uploaded_by'=>'44'],
            ]);
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::EXISTING_OK_PATH, 'ok.png', $this->isType('array'))
                ->willThrowException(new \InvalidArgumentException('File content does not match the declared MIME type'));
            $r2->expects($this->never())->method('deleteFile');
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::EXISTING_OK_PATH,'original_name'=>'ok.png'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(400, $resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertSame('INVALID_FILE_CONTENT', $payload['code']);
    }

    public function testConfirmDoesNotDeleteAlreadyPersistedOwnedObjectWhenContentValidationFails(): void
    {
        $owned = new File();
        $owned->user_id = 33;
        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::EXISTING_OK_PATH)
            ->willReturn($owned);
        $fileMeta->expects($this->never())->method('createRecord');

        $c = $this->controller(['id'=>33], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::EXISTING_OK_PATH,
                'size'=>256,
                'mime_type'=>self::MIME_PNG,
                'metadata'=>['uploaded_by'=>'33'],
            ]);
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::EXISTING_OK_PATH, 'bad-name.txt', $this->isType('array'))
                ->willThrowException(new \InvalidArgumentException('File extension not allowed. Allowed extensions: jpg, jpeg, png, gif, webp'));
            $r2->expects($this->never())->method('deleteFile');
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::EXISTING_OK_PATH,'original_name'=>'bad-name.txt'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(400, $resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertSame('INVALID_FILE_CONTENT', $payload['code']);
    }

    public function testR2DiagnosticsResponseRedactsStorageIdentifiers(): void
    {
        $c = $this->controller(['id'=>1, 'is_admin'=>1], function($r2){
            $r2->method('diagnostics')->willReturn([
                'bucket' => 'private-bucket',
                'endpoint' => 'https://accountid1234567890.r2.cloudflarestorage.com/private-bucket',
                'public_base' => 'https://pub-accountid1234567890.r2.dev/private-bucket',
                'endpoint_has_bucket_path' => true,
                'recommended_endpoint' => 'https://accountid1234567890.r2.cloudflarestorage.com',
                'tls_verify' => true,
                'checks' => [
                    'list_objects' => true,
                    'presign_put' => true,
                    'presign_sample' => [
                        'file_path' => 'diagnostics/_probe_secret.txt',
                        'url_length' => 512,
                    ],
                ],
                'errors' => [
                    'ListObjects failed for https://accountid1234567890.r2.cloudflarestorage.com/private-bucket',
                    'Plain host accountid1234567890.r2.cloudflarestorage.com and bucket private-bucket leaked by SDK',
                ],
                'timestamp' => '2026-05-18T00:00:00+00:00',
            ]);
        });

        $resp = $c->r2Diagnostics(makeRequest('GET', '/files/r2/diagnostics'), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $data = $payload['data'];
        $this->assertArrayNotHasKey('bucket', $data);
        $this->assertArrayNotHasKey('endpoint', $data);
        $this->assertArrayNotHasKey('public_base', $data);
        $this->assertArrayNotHasKey('recommended_endpoint', $data);
        $this->assertSame(['bucket' => true, 'endpoint' => true, 'public_base' => true], $data['storage_configured']);
        $this->assertArrayNotHasKey('file_path', $data['checks']['presign_sample']);
        $this->assertStringNotContainsString('accountid1234567890', $data['errors'][0]);
        $this->assertStringNotContainsString('private-bucket', $data['errors'][0]);
        $this->assertStringNotContainsString('accountid1234567890', $data['errors'][1]);
        $this->assertStringNotContainsString('private-bucket', $data['errors'][1]);
    }

    public function testR2DiagnosticsRedactionKeepsLongNonHexWordsReadable(): void
    {
        $c = $this->controller(['id'=>1, 'is_admin'=>1], function($r2){
            $r2->method('diagnostics')->willReturn([
                'bucket' => 'private-bucket',
                'endpoint' => 'https://accountid1234567890.r2.cloudflarestorage.com/private-bucket',
                'public_base' => 'https://pub-accountid1234567890.r2.dev/private-bucket',
                'endpoint_has_bucket_path' => false,
                'tls_verify' => true,
                'checks' => [],
                'errors' => ['confirmDirectUpload failed with request abcdef1234567890'],
                'timestamp' => '2026-05-18T00:00:00+00:00',
            ]);
        });

        $resp = $c->r2Diagnostics(makeRequest('GET', '/files/r2/diagnostics'), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $error = $payload['data']['errors'][0];
        $this->assertStringContainsString('confirmDirectUpload', $error);
        $this->assertStringNotContainsString('abcdef1234567890', $error);
        $this->assertStringContainsString('[redacted-id]', $error);
    }

    public function testR2DiagnosticsRedactionKeepsExceptionClassNamesReadable(): void
    {
        $c = $this->controller(['id'=>1, 'is_admin'=>1], function($r2){
            $r2->method('diagnostics')->willReturn([
                'bucket' => 'private-bucket',
                'endpoint' => 'https://accountid1234567890.r2.cloudflarestorage.com/ResourceNotFoundException',
                'public_base' => 'https://pub-accountid1234567890.r2.dev/private-bucket',
                'endpoint_has_bucket_path' => false,
                'tls_verify' => true,
                'checks' => [],
                'errors' => ['ResourceNotFoundException while reading private-bucket'],
                'timestamp' => '2026-05-18T00:00:00+00:00',
            ]);
        });

        $resp = $c->r2Diagnostics(makeRequest('GET', '/files/r2/diagnostics'), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $error = $payload['data']['errors'][0];
        $this->assertStringContainsString('ResourceNotFoundException', $error);
        $this->assertStringNotContainsString('private-bucket', $error);
    }

    public function testR2DiagnosticsRedactionDoesNotMaskCommonLowEntropyTerms(): void
    {
        $c = $this->controller(['id'=>1, 'is_admin'=>1], function($r2){
            $r2->method('diagnostics')->willReturn([
                'bucket' => 'dev',
                'endpoint' => 'https://api.r2.dev/dev',
                'public_base' => 'https://api.r2.dev/dev',
                'endpoint_has_bucket_path' => false,
                'tls_verify' => true,
                'checks' => [],
                'errors' => ['dev api prod labels should remain readable'],
                'timestamp' => '2026-05-18T00:00:00+00:00',
            ]);
        });

        $resp = $c->r2Diagnostics(makeRequest('GET', '/files/r2/diagnostics'), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertSame('dev api prod labels should remain readable', $payload['data']['errors'][0]);
    }

    public function testR2DiagnosticsFailureDoesNotExposeExceptionDetails(): void
    {
        $c = $this->controller(['id'=>1, 'is_admin'=>1], function($r2){
            $r2->method('diagnostics')->willThrowException(new \RuntimeException('private-bucket accountid1234567890'));
        });

        $resp = $c->r2Diagnostics(makeRequest('GET', '/files/r2/diagnostics'), new \Slim\Psr7\Response());

        $this->assertSame(500, $resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertSame('Diagnostics failed', $payload['message']);
        $this->assertStringNotContainsString('private-bucket', (string)$resp->getBody());
        $this->assertStringNotContainsString('accountid1234567890', (string)$resp->getBody());
    }

    public function testR2DiagnosticsRouteRequiresAdminMiddleware(): void
    {
        $routes = file_get_contents(__DIR__ . '/../../../src/routes.php');

        $this->assertMatchesRegularExpression(
            "#/r2/diagnostics'.*FileUploadController::class, 'r2Diagnostics'.*->add\\(AdminMiddleware::class\\)#s",
            $routes
        );
    }

    public function testConfirmWithoutSha256BackfillsExistingOwnerlessRecord(): void
    {
        $existing = $this->getMockBuilder(File::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $existing->file_path = self::OWNERLESS_UPLOAD_PATH;
        $existing->user_id = null;
        $existing->mime_type = null;
        $existing->size = 0;
        $existing->original_name = null;
        $existing->reference_count = 0;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::OWNERLESS_UPLOAD_PATH)
            ->willReturn($existing);

        $existing->expects($this->once())
            ->method('save');

        $c = $this->controller(['id'=>14], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::OWNERLESS_UPLOAD_PATH,
                'size'=>2048,
                'mime_type'=>self::MIME_JPEG,
                'metadata'=>['uploaded_by'=>'14']
            ]);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::OWNERLESS_UPLOAD_PATH,'original_name'=>'ownerless.jpg'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200,$resp->getStatusCode());
        $this->assertSame(14, $existing->user_id);
        $this->assertSame('ownerless.jpg', $existing->original_name);
        $this->assertSame(self::MIME_JPEG, $existing->mime_type);
        $this->assertSame(2048, $existing->size);
        $this->assertSame(1, $existing->reference_count);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $existing->sha256);
    }

    public function testConfirmReturnsConflictWhenExistingFileBelongsToAnotherUser(): void
    {
        $existing = $this->getMockBuilder(File::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $existing->file_path = self::OWNERLESS_UPLOAD_PATH;
        $existing->user_id = 99;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::OWNERLESS_UPLOAD_PATH)
            ->willReturn($existing);

        $existing->expects($this->never())->method('save');

        $c = $this->controller(['id'=>14], function($r2){
            $r2->method('getFileInfo')->willReturn([
                'file_path'=>self::OWNERLESS_UPLOAD_PATH,
                'size'=>2048,
                'mime_type'=>self::MIME_JPEG,
                'metadata'=>['uploaded_by'=>'14']
            ]);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::OWNERLESS_UPLOAD_PATH,'original_name'=>'ownerless.jpg'
        ]), new \Slim\Psr7\Response());

        $this->assertSame(409,$resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertSame('FILE_OWNERSHIP_CONFLICT', $payload['code']);
    }

    public function testInitMultipartRegistersUploadOwnership(): void
    {
        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->expects($this->once())
            ->method('registerUpload')
            ->with('up-1', self::MULTIPART_FILE_PATH, 42, self::MULTIPART_SHA256);

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_JPEG]);
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->expects($this->once())
                ->method('initMultipartUpload')
                ->with(
                    'big.jpg',
                    'uploads',
                    self::MIME_JPEG,
                    $this->callback(function(array $metadata): bool {
                        return ($metadata['uploaded_by'] ?? null) === '42'
                            && ($metadata['original_name'] ?? null) === 'big.jpg'
                            && ($metadata['sha256'] ?? null) === self::MULTIPART_SHA256;
                    })
                )
                ->willReturn([
                    'upload_id' => 'up-1',
                    'file_path' => self::MULTIPART_FILE_PATH,
                    'public_url' => 'https://cdn/big.jpg'
                ]);
        }, null, $multipart);

        $resp = $c->initMultipartUpload(makeRequest('POST', '/files/multipart/init', [
            'original_name' => 'big.jpg',
            'directory' => 'uploads',
            'mime_type' => self::MIME_JPEG,
            'sha256' => self::MULTIPART_SHA256
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testInitMultipartRejectsInvalidExtension(): void
    {
        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->expects($this->never())->method('registerUpload');

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_JPEG]);
            $r2->expects($this->never())->method('initMultipartUpload');
        }, null, $multipart);

        $resp = $c->initMultipartUpload(makeRequest('POST', '/files/multipart/init', [
            'original_name' => 'big.txt',
            'directory' => 'uploads',
            'mime_type' => self::MIME_JPEG,
            'sha256' => self::MULTIPART_SHA256
        ]), new \Slim\Psr7\Response());

        $this->assertSame(400, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('File extension not allowed', $payload['message']);
    }

    public function testInitMultipartRejectsAdminOnlyDirectoryForNonAdmin(): void
    {
        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->expects($this->never())->method('registerUpload');

        $c = $this->controller(['id' => 42, 'is_admin' => 0], function($r2) {
            $r2->expects($this->never())->method('initMultipartUpload');
        }, null, $multipart);

        $resp = $c->initMultipartUpload(makeRequest('POST', '/files/multipart/init', [
            'original_name' => 'badge.jpg',
            'directory' => 'badges',
            'mime_type' => self::MIME_JPEG,
            'sha256' => self::MULTIPART_SHA256
        ]), new \Slim\Psr7\Response());

        $this->assertSame(403, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('ADMIN_UPLOAD_DIRECTORY_REQUIRED', $payload['code']);
    }

    public function testInitMultipartAllowsAdminOnlyDirectoryForAdmin(): void
    {
        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->expects($this->once())
            ->method('registerUpload')
            ->with('up-admin', 'badges/2026/05/badge.jpg', 42, self::MULTIPART_SHA256);

        $c = $this->controller(['id' => 42, 'is_admin' => 1], function($r2) {
            $r2->method('getAllowedExtensions')->willReturn(['jpg']);
            $r2->method('getAllowedMimeTypes')->willReturn([self::MIME_JPEG]);
            $r2->expects($this->once())
                ->method('initMultipartUpload')
                ->with(
                    'badge.jpg',
                    'badges',
                    self::MIME_JPEG,
                    $this->callback(function(array $metadata): bool {
                        return ($metadata['uploaded_by'] ?? null) === '42'
                            && ($metadata['original_name'] ?? null) === 'badge.jpg'
                            && ($metadata['sha256'] ?? null) === self::MULTIPART_SHA256;
                    })
                )
                ->willReturn([
                    'upload_id' => 'up-admin',
                    'file_path' => 'badges/2026/05/badge.jpg',
                    'public_url' => 'https://cdn/badge.jpg'
                ]);
        }, null, $multipart);

        $resp = $c->initMultipartUpload(makeRequest('POST', '/files/multipart/init', [
            'original_name' => 'badge.jpg',
            'directory' => 'badges',
            'mime_type' => self::MIME_JPEG,
            'sha256' => self::MULTIPART_SHA256
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testMultipartPartDeniedForDifferentOwner(): void
    {
        $upload = new MultipartUpload();
        $upload->upload_id = 'up-2';
        $upload->file_path = self::MULTIPART_FILE_PATH;
        $upload->user_id = 77;

        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->method('findActiveUpload')->with('up-2')->willReturn($upload);

        $c = $this->controller(['id' => 42], null, null, $multipart);
        $resp = $c->getMultipartPartUrl(makeRequest('GET', '/files/multipart/part', null, [
            'file_path' => self::MULTIPART_FILE_PATH,
            'upload_id' => 'up-2',
            'part_number' => 1
        ]), new \Slim\Psr7\Response());

        $this->assertSame(403, $resp->getStatusCode());
    }

    public function testCompleteMultipartClearsOwnershipTracking(): void
    {
        $upload = new MultipartUpload();
        $upload->upload_id = 'up-3';
        $upload->file_path = self::MULTIPART_FILE_PATH;
        $upload->sha256 = self::MULTIPART_SHA256;
        $upload->user_id = 42;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::MULTIPART_FILE_PATH)
            ->willReturn(null);
        $fileMeta->expects($this->once())
            ->method('findBySha256')
            ->with(self::MULTIPART_SHA256)
            ->willReturn(null);
        $fileMeta->expects($this->once())
            ->method('createRecord')
            ->with($this->callback(function(array $data): bool {
                return ($data['file_path'] ?? null) === self::MULTIPART_FILE_PATH
                    && ($data['sha256'] ?? null) === self::MULTIPART_SHA256
                    && ($data['user_id'] ?? null) === 42
                    && ($data['mime_type'] ?? null) === self::MIME_JPEG
                    && ($data['size'] ?? null) === 98765
                    && ($data['reference_count'] ?? null) === 1;
            }))
            ->willReturn(new File());

        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->method('findActiveUpload')->with('up-3')->willReturn($upload);
        $multipart->expects($this->once())->method('clearUpload')->with('up-3');

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::MULTIPART_FILE_PATH, 'big.jpg', $this->callback(function(array $fileInfo): bool {
                    return ($fileInfo['metadata']['uploaded_by'] ?? null) === '42'
                        && ($fileInfo['metadata']['original_name'] ?? null) === 'big.jpg';
                }));
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => [
                    'uploaded_by' => '42',
                    'original_name' => 'big.jpg'
                ]
            ]);
        }, $fileMeta, $multipart);

        $resp = $c->completeMultipartUpload(makeRequest('POST', self::ROUTE_MULTIPART_COMPLETE, [
            'file_path' => self::MULTIPART_FILE_PATH,
            'upload_id' => 'up-3',
            'sha256' => self::MULTIPART_SHA256,
            'parts' => [['part_number' => 1, 'etag' => 'etag-1']]
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testCompleteMultipartUsesRequestOriginalNameWhenLegacyMetadataIsMissing(): void
    {
        $upload = new MultipartUpload();
        $upload->upload_id = 'up-legacy';
        $upload->file_path = self::MULTIPART_FILE_PATH;
        $upload->sha256 = self::MULTIPART_SHA256;
        $upload->user_id = 42;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::MULTIPART_FILE_PATH)
            ->willReturn(null);
        $fileMeta->expects($this->once())
            ->method('findBySha256')
            ->with(self::MULTIPART_SHA256)
            ->willReturn(null);
        $fileMeta->expects($this->once())
            ->method('createRecord')
            ->with($this->callback(function(array $data): bool {
                return ($data['file_path'] ?? null) === self::MULTIPART_FILE_PATH
                    && ($data['original_name'] ?? null) === 'legacy.jpg'
                    && ($data['sha256'] ?? null) === self::MULTIPART_SHA256;
            }))
            ->willReturn(new File());

        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->method('findActiveUpload')->with('up-legacy')->willReturn($upload);
        $multipart->expects($this->once())->method('clearUpload')->with('up-legacy');

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->method('getAllowedExtensions')->willReturn(['jpg', 'jpeg', 'png', 'webp']);
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::MULTIPART_FILE_PATH, 'legacy.jpg', $this->callback(function(array $fileInfo): bool {
                    return ($fileInfo['metadata']['original_name'] ?? null) === 'legacy.jpg';
                }));
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => []
            ]);
        }, $fileMeta, $multipart);

        $resp = $c->completeMultipartUpload(makeRequest('POST', self::ROUTE_MULTIPART_COMPLETE, [
            'file_path' => self::MULTIPART_FILE_PATH,
            'upload_id' => 'up-legacy',
            'original_name' => 'legacy.jpg',
            'parts' => [['part_number' => 1, 'etag' => 'etag-1']]
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testCompleteMultipartDeletesObjectAndClearsTrackingWhenContentValidationFails(): void
    {
        $upload = new MultipartUpload();
        $upload->upload_id = 'up-invalid';
        $upload->file_path = self::MULTIPART_FILE_PATH;
        $upload->sha256 = self::MULTIPART_SHA256;
        $upload->user_id = 42;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->never())->method('createRecord');

        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->method('findActiveUpload')->with('up-invalid')->willReturn($upload);
        $multipart->expects($this->once())->method('clearUpload')->with('up-invalid');

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => [
                    'uploaded_by' => '42',
                    'original_name' => 'big.jpg'
                ]
            ]);
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::MULTIPART_FILE_PATH, 'big.jpg', $this->isType('array'))
                ->willThrowException(new \InvalidArgumentException('File content does not match the declared MIME type'));
            $r2->expects($this->once())
                ->method('deleteFile')
                ->with(self::MULTIPART_FILE_PATH, 42)
                ->willReturn(true);
        }, $fileMeta, $multipart);

        $resp = $c->completeMultipartUpload(makeRequest('POST', self::ROUTE_MULTIPART_COMPLETE, [
            'file_path' => self::MULTIPART_FILE_PATH,
            'upload_id' => 'up-invalid',
            'parts' => [['part_number' => 1, 'etag' => 'etag-1']]
        ]), new \Slim\Psr7\Response());

        $this->assertSame(400, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('INVALID_FILE_CONTENT', $payload['code']);
    }

    public function testCompleteMultipartClearsTrackingWhenContentVerificationFailsUnexpectedly(): void
    {
        $upload = new MultipartUpload();
        $upload->upload_id = 'up-verify-error';
        $upload->file_path = self::MULTIPART_FILE_PATH;
        $upload->sha256 = self::MULTIPART_SHA256;
        $upload->user_id = 42;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->never())->method('createRecord');

        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->method('findActiveUpload')->with('up-verify-error')->willReturn($upload);
        $multipart->expects($this->once())->method('clearUpload')->with('up-verify-error');

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => [
                    'uploaded_by' => '42',
                    'original_name' => 'big.jpg'
                ]
            ]);
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::MULTIPART_FILE_PATH, 'big.jpg', $this->isType('array'))
                ->willThrowException(new \RuntimeException('Failed to verify uploaded file content'));
            $r2->expects($this->never())->method('deleteFile');
        }, $fileMeta, $multipart);

        $resp = $c->completeMultipartUpload(makeRequest('POST', self::ROUTE_MULTIPART_COMPLETE, [
            'file_path' => self::MULTIPART_FILE_PATH,
            'upload_id' => 'up-verify-error',
            'parts' => [['part_number' => 1, 'etag' => 'etag-1']]
        ]), new \Slim\Psr7\Response());

        $this->assertSame(500, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('FILE_CONTENT_VALIDATION_ERROR', $payload['code']);
    }

    public function testCompleteMultipartCrossOwnerSha256CreatesRecordWithoutPersistingHash(): void
    {
        $upload = new MultipartUpload();
        $upload->upload_id = 'up-7';
        $upload->file_path = self::MULTIPART_FILE_PATH;
        $upload->sha256 = self::MULTIPART_SHA256;
        $upload->user_id = 42;

        $existing = new File();
        $existing->file_path = self::MULTIPART_EXISTING_FILE_PATH;
        $existing->user_id = 77;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::MULTIPART_FILE_PATH)
            ->willReturn(null);
        $fileMeta->expects($this->once())
            ->method('findBySha256')
            ->with(self::MULTIPART_SHA256)
            ->willReturn($existing);
        $fileMeta->expects($this->once())
            ->method('createRecord')
            ->with($this->callback(function(array $data): bool {
                return ($data['file_path'] ?? null) === self::MULTIPART_FILE_PATH
                    && ($data['user_id'] ?? null) === 42
                    && is_string($data['sha256'] ?? null)
                    && preg_match('/^[a-f0-9]{64}$/', $data['sha256']) === 1
                    && ($data['sha256'] ?? null) !== self::MULTIPART_SHA256;
            }))
            ->willReturn(new File());

        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->method('findActiveUpload')->with('up-7')->willReturn($upload);
        $multipart->expects($this->once())->method('clearUpload')->with('up-7');

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::MULTIPART_FILE_PATH, 'big.jpg', $this->callback(function(array $fileInfo): bool {
                    return ($fileInfo['metadata']['uploaded_by'] ?? null) === '42'
                        && ($fileInfo['metadata']['original_name'] ?? null) === 'big.jpg'
                        && ($fileInfo['metadata']['sha256'] ?? null) === self::MULTIPART_SHA256;
                }));
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => [
                    'uploaded_by' => '42',
                    'original_name' => 'big.jpg',
                    'sha256' => self::MULTIPART_SHA256,
                ]
            ]);
        }, $fileMeta, $multipart);

        $resp = $c->completeMultipartUpload(makeRequest('POST', self::ROUTE_MULTIPART_COMPLETE, [
            'file_path' => self::MULTIPART_FILE_PATH,
            'upload_id' => 'up-7',
            'parts' => [['part_number' => 1, 'etag' => 'etag-1']]
        ]), new \Slim\Psr7\Response());

        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testCompleteMultipartRejectsMissingSha256ForNewFileRecord(): void
    {
        $upload = new MultipartUpload();
        $upload->upload_id = 'up-4';
        $upload->file_path = self::MULTIPART_FILE_PATH;
        $upload->sha256 = null;
        $upload->user_id = 42;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::MULTIPART_FILE_PATH)
            ->willReturn(null);

        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->method('findActiveUpload')->with('up-4')->willReturn($upload);

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->expects($this->never())->method('completeMultipartUpload');
        }, $fileMeta, $multipart);

        $resp = $c->completeMultipartUpload(makeRequest('POST', self::ROUTE_MULTIPART_COMPLETE, [
            'file_path' => self::MULTIPART_FILE_PATH,
            'upload_id' => 'up-4',
            'parts' => [['part_number' => 1, 'etag' => 'etag-1']]
        ]), new \Slim\Psr7\Response());

        $this->assertSame(400, $resp->getStatusCode());
    }

    public function testCompleteMultipartReturnsConflictAndClearsTrackingWhenFileBelongsToAnotherUser(): void
    {
        $upload = new MultipartUpload();
        $upload->upload_id = 'up-5';
        $upload->file_path = self::MULTIPART_FILE_PATH;
        $upload->sha256 = self::MULTIPART_SHA256;
        $upload->user_id = 42;

        $existing = $this->getMockBuilder(File::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['save'])
            ->getMock();
        $existing->file_path = self::MULTIPART_FILE_PATH;
        $existing->user_id = 77;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::MULTIPART_FILE_PATH)
            ->willReturn($existing);

        $existing->expects($this->never())->method('save');

        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->method('findActiveUpload')->with('up-5')->willReturn($upload);
        $multipart->expects($this->once())->method('clearUpload')->with('up-5');

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::MULTIPART_FILE_PATH, 'big.jpg', $this->callback(function(array $fileInfo): bool {
                    return ($fileInfo['metadata']['uploaded_by'] ?? null) === '42'
                        && ($fileInfo['metadata']['original_name'] ?? null) === 'big.jpg';
                }));
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => [
                    'uploaded_by' => '42',
                    'original_name' => 'big.jpg'
                ]
            ]);
        }, $fileMeta, $multipart);

        $resp = $c->completeMultipartUpload(makeRequest('POST', self::ROUTE_MULTIPART_COMPLETE, [
            'file_path' => self::MULTIPART_FILE_PATH,
            'upload_id' => 'up-5',
            'parts' => [['part_number' => 1, 'etag' => 'etag-1']]
        ]), new \Slim\Psr7\Response());

        $this->assertSame(409, $resp->getStatusCode());
        $payload = json_decode((string) $resp->getBody(), true);
        $this->assertSame('FILE_OWNERSHIP_CONFLICT', $payload['code']);
    }

    public function testCompleteMultipartClearsTrackingWhenOwnershipPersistenceFails(): void
    {
        $upload = new MultipartUpload();
        $upload->upload_id = 'up-6';
        $upload->file_path = self::MULTIPART_FILE_PATH;
        $upload->sha256 = self::MULTIPART_SHA256;
        $upload->user_id = 42;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->expects($this->once())
            ->method('findByFilePath')
            ->with(self::MULTIPART_FILE_PATH)
            ->willReturn(null);
        $fileMeta->expects($this->once())
            ->method('createRecord')
            ->willThrowException(new \RuntimeException('db down'));

        $multipart = $this->createMock(MultipartUploadService::class);
        $multipart->method('findActiveUpload')->with('up-6')->willReturn($upload);
        $multipart->expects($this->once())->method('clearUpload')->with('up-6');

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->expects($this->once())
                ->method('validateDirectUploadObject')
                ->with(self::MULTIPART_FILE_PATH, 'big.jpg', $this->callback(function(array $fileInfo): bool {
                    return ($fileInfo['metadata']['uploaded_by'] ?? null) === '42'
                        && ($fileInfo['metadata']['original_name'] ?? null) === 'big.jpg';
                }));
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => [
                    'uploaded_by' => '42',
                    'original_name' => 'big.jpg'
                ]
            ]);
        }, $fileMeta, $multipart);

        $resp = $c->completeMultipartUpload(makeRequest('POST', self::ROUTE_MULTIPART_COMPLETE, [
            'file_path' => self::MULTIPART_FILE_PATH,
            'upload_id' => 'up-6',
            'parts' => [['part_number' => 1, 'etag' => 'etag-1']]
        ]), new \Slim\Psr7\Response());

        $this->assertSame(500, $resp->getStatusCode());
    }

    public function testGetPrivateInfoAllowedForPersistedOwnerRecord(): void
    {
        $ownedFile = new File();
        $ownedFile->file_path = self::PRIVATE_UPLOAD_PATH;
        $ownedFile->user_id = 42;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->method('isPubliclyReadablePath')->with(self::PRIVATE_UPLOAD_PATH)->willReturn(false);
        $fileMeta->method('findByFilePath')->with(self::PRIVATE_UPLOAD_PATH)->willReturn($ownedFile);

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->method('getFileInfo')->willReturn([
                'file_path' => self::PRIVATE_UPLOAD_PATH,
                'metadata' => []
            ]);
        }, $fileMeta);

        $resp = $c->getFileInfo(makeRequest('GET', '/files/uploads/info'), new \Slim\Psr7\Response(), ['path' => self::PRIVATE_UPLOAD_ENCODED_PATH]);
        $this->assertSame(200, $resp->getStatusCode());
    }

    public function testDeletePrivateFileAllowedForPersistedOwnerRecord(): void
    {
        $ownedFile = new File();
        $ownedFile->file_path = self::PRIVATE_UPLOAD_PATH;
        $ownedFile->user_id = 42;

        $fileMeta = $this->createMock(FileMetadataService::class);
        $fileMeta->method('findByFilePath')->with(self::PRIVATE_UPLOAD_PATH)->willReturn($ownedFile);

        $c = $this->controller(['id' => 42], function($r2) {
            $r2->method('getFileInfo')->willReturn([
                'file_path' => self::PRIVATE_UPLOAD_PATH,
                'metadata' => []
            ]);
            $r2->method('deleteFile')->with(self::PRIVATE_UPLOAD_PATH, 42)->willReturn(true);
        }, $fileMeta);

        $resp = $c->deleteFile(makeRequest('DELETE', '/files/uploads/delete'), new \Slim\Psr7\Response(), ['path' => self::PRIVATE_UPLOAD_ENCODED_PATH]);
        $this->assertSame(200, $resp->getStatusCode());
    }

    private function makeUploadedFile(string $clientFilename, string $content = 'image-bytes'): \Slim\Psr7\UploadedFile
    {
        $stream = (new \Slim\Psr7\Factory\StreamFactory())->createStream($content);

        return new \Slim\Psr7\UploadedFile(
            $stream,
            $clientFilename,
            self::MIME_JPEG,
            strlen($content),
            UPLOAD_ERR_OK
        );
    }
}

