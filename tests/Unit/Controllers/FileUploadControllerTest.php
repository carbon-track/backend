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
                'file_path'=>self::NEW_UPLOAD_PATH,'size'=>10,'mime_type'=>'image/jpeg'
            ]);
        }, $fileMeta);

        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::NEW_UPLOAD_PATH,'original_name'=>'new.jpg','sha256'=>str_repeat('b',64)
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
        $payload = json_decode((string)$resp->getBody(), true);
        $this->assertEquals(1,$payload['data']['reference_count']);
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
                'file_path'=>self::EXISTING_OK_PATH,'size'=>10,'mime_type'=>self::MIME_JPEG
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
                'file_path'=>self::EXISTING_OK_PATH,'size'=>1,'mime_type'=>self::MIME_JPEG
            ]);
            // logDirectUploadAudit is void; no return value expectation needed
        }, $fileMeta);
        $resp = $c->confirmDirectUpload(makeRequest('POST', self::ROUTE_CONFIRM,[
            'file_path'=>self::EXISTING_OK_PATH,'original_name'=>'ok.jpg'
        ]), new \Slim\Psr7\Response());
        $this->assertSame(200,$resp->getStatusCode());
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
                'metadata'=>[]
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
                'metadata'=>[]
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
            $r2->method('initMultipartUpload')->willReturn([
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
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => ['original_name' => 'big.jpg']
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
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => [
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
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => ['original_name' => 'big.jpg']
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
            $r2->method('completeMultipartUpload')->willReturn([
                'success' => true,
                'file_path' => self::MULTIPART_FILE_PATH
            ]);
            $r2->method('getFileInfo')->with(self::MULTIPART_FILE_PATH)->willReturn([
                'file_path' => self::MULTIPART_FILE_PATH,
                'size' => 98765,
                'mime_type' => self::MIME_JPEG,
                'metadata' => ['original_name' => 'big.jpg']
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
}

