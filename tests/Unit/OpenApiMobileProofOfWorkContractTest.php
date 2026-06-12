<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit;

use PHPUnit\Framework\TestCase;

class OpenApiMobileProofOfWorkContractTest extends TestCase
{
    public function testMobileProofOfWorkSchemasDocumentRequiredHeaders(): void
    {
        $openApi = $this->openApi();
        $schemas = $openApi['components']['schemas'] ?? [];
        $schemaNames = [
            'RegisterRequest',
            'LoginRequest',
            'ForgotPasswordRequest',
            'SendVerificationCodeRequest',
            'VerifyEmailRequest',
            'UpdateProfileRequest',
            'CreateSupportTicketRequest',
            'CreateMySupportTicketMessageRequest',
        ];

        foreach ($schemaNames as $schemaName) {
            $schema = $schemas[$schemaName] ?? null;
            $this->assertIsArray($schema, "{$schemaName} schema must exist");

            $description = (string) ($schema['properties']['cf_turnstile_response']['description'] ?? '');
            $this->assertStringContainsString('X-Client-Platform', $description, "{$schemaName} must document mobile platform header");
            $this->assertStringContainsString('X-Mobile-Client-Token', $description, "{$schemaName} must document mobile client token header");
        }
    }

    public function testMobileProofOfWorkOperationsDeclareRequiredHeaders(): void
    {
        $paths = $this->openApi()['paths'] ?? [];
        $operations = [
            ['/api/auth/register', 'post'],
            ['/api/auth/login', 'post'],
            ['/api/auth/forgot-password', 'post'],
            ['/api/auth/send-verification-code', 'post'],
            ['/api/auth/verify-email', 'post'],
            ['/api/v1/auth/register', 'post'],
            ['/api/v1/auth/login', 'post'],
            ['/api/v1/auth/forgot-password', 'post'],
            ['/api/v1/auth/send-verification-code', 'post'],
            ['/api/v1/auth/verify-email', 'post'],
            ['/api/v1/users/me/profile', 'put'],
            ['/api/v1/tickets', 'post'],
            ['/api/v1/tickets/{ticketId}/messages', 'post'],
        ];

        foreach ($operations as [$path, $method]) {
            $operation = $paths[$path][$method] ?? null;
            $this->assertIsArray($operation, "{$method} {$path} operation must exist");
            $headers = [];
            foreach (($operation['parameters'] ?? []) as $parameter) {
                if (($parameter['in'] ?? null) === 'header' && isset($parameter['name'])) {
                    $headers[] = $parameter['name'];
                }
            }

            $this->assertContains('X-Client-Platform', $headers, "{$method} {$path} must declare X-Client-Platform");
            $this->assertContains('X-Mobile-Client-Token', $headers, "{$method} {$path} must declare X-Mobile-Client-Token");
        }
    }

    public function testAdminAiChatDocumentsAlreadyDecidedConflict(): void
    {
        $paths = $this->openApi()['paths'] ?? [];
        $operation = $paths['/api/v1/admin/ai/chat']['post'] ?? null;
        $this->assertIsArray($operation);

        $conflict = $operation['responses']['409'] ?? null;
        $this->assertIsArray($conflict, 'Admin AI chat must document duplicate decision conflict');
        $this->assertStringContainsString('PROPOSAL_ALREADY_DECIDED', json_encode($conflict, JSON_UNESCAPED_UNICODE));
    }

    public function testSupportStaffMessageSchemaDoesNotExposeUserChallengeFields(): void
    {
        $schemas = $this->openApi()['components']['schemas'] ?? [];
        $schema = $schemas['CreateSupportTicketMessageRequest'] ?? null;
        $this->assertIsArray($schema);

        $encoded = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('cf_turnstile_response', $encoded);
        $this->assertStringNotContainsString('pow_challenge', $encoded);
        $this->assertStringNotContainsString('X-Mobile-Client-Token', $encoded);
    }

    public function testUploadDirectoryGuardResponsesDocumentAdminOnlyFailure(): void
    {
        $paths = $this->openApi()['paths'] ?? [];
        $operations = [
            ['/api/v1/files/confirm', 'post'],
            ['/api/v1/files/multipart/init', 'post'],
            ['/api/v1/files/presign', 'post'],
            ['/api/v1/files/upload', 'post'],
            ['/api/v1/files/upload-multiple', 'post'],
        ];

        foreach ($operations as [$path, $method]) {
            $operation = $paths[$path][$method] ?? null;
            $this->assertIsArray($operation, "{$method} {$path} operation must exist");

            $forbidden = $operation['responses']['403'] ?? null;
            $this->assertIsArray($forbidden, "{$method} {$path} must document admin-only upload directory failures");
            $this->assertStringContainsString(
                'ADMIN_UPLOAD_DIRECTORY_REQUIRED',
                json_encode($forbidden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                "{$method} {$path} must document ADMIN_UPLOAD_DIRECTORY_REQUIRED"
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function openApi(): array
    {
        $path = dirname(__DIR__, 2) . '/openapi.json';
        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
