<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Unit;

use CarbonTrack\Support\OpenApiContractChecker;
use PHPUnit\Framework\TestCase;

final class OpenApiContractCheckerTest extends TestCase
{
    public function testReportsRuntimeAndOperationQualityIssues(): void
    {
        $spec = [
            'paths' => [
                '/api/v1/widgets/{id}' => [
                    'get' => [
                        'tags' => [],
                        'summary' => '',
                        'parameters' => [
                            [
                                'name' => 'id',
                                'in' => 'path',
                                'required' => false,
                                'schema' => ['type' => 'integer'],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'OK',
                                'content' => [
                                    'application/json' => [],
                                ],
                            ],
                        ],
                    ],
                ],
                '/api/v1/spec-only' => [
                    'get' => [
                        'operationId' => 'specOnly',
                        'tags' => ['Diagnostics'],
                        'summary' => 'Spec only',
                        'description' => 'Documented but not registered at runtime.',
                        'responses' => [
                            '200' => ['description' => 'OK'],
                            '404' => ['description' => 'Not found'],
                        ],
                    ],
                ],
            ],
        ];

        $runtimeRoutes = [
            'GET /api/v1/widgets/{id}' => [
                'path' => '/api/v1/widgets/{id}',
                'handler' => 'CarbonTrack\\Controllers\\WidgetController::show',
                'handler_exists' => false,
            ],
            'POST /api/v1/runtime-only' => [
                'path' => '/api/v1/runtime-only',
                'handler' => 'closure',
                'handler_exists' => true,
            ],
        ];

        $result = OpenApiContractChecker::checkDocuments($spec, $runtimeRoutes);

        $this->assertFalse($result['ok']);
        $this->assertContains('GET /api/v1/spec-only', $result['issues']['missing_in_runtime']);
        $this->assertContains('POST /api/v1/runtime-only', $result['issues']['missing_in_spec']);
        $this->assertSame(
            'CarbonTrack\\Controllers\\WidgetController::show',
            $result['issues']['broken_handlers']['GET /api/v1/widgets/{id}']
        );
        $this->assertContains('GET /api/v1/widgets/{id}', $result['issues']['missing_operation_id']);
        $this->assertContains('GET /api/v1/widgets/{id}', $result['issues']['missing_tags']);
        $this->assertContains('GET /api/v1/widgets/{id}', $result['issues']['missing_summary']);
        $this->assertContains('GET /api/v1/widgets/{id}', $result['issues']['missing_description']);
        $this->assertContains('GET /api/v1/widgets/{id}', $result['issues']['missing_error_response']);
        $this->assertContains(
            'GET /api/v1/widgets/{id} response 200 application/json',
            $result['issues']['response_content_missing_schema']
        );
        $this->assertSame(
            ['missing' => [], 'not_required' => ['id'], 'extra' => []],
            $result['issues']['path_parameter_mismatches']['GET /api/v1/widgets/{id}']
        );
    }

    public function testAcceptsMultipartRequestSchemasAndDuplicateOperationIdsAreRejected(): void
    {
        $spec = [
            'paths' => [
                '/api/v1/files/upload' => [
                    'post' => $this->operation('duplicateUpload', [
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'multipart/form-data' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['file'],
                                        'properties' => [
                                            'file' => ['type' => 'string', 'format' => 'binary'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                ],
                '/api/v1/files/upload-multiple' => [
                    'post' => $this->operation('duplicateUpload'),
                ],
            ],
        ];

        $runtimeRoutes = [
            'POST /api/v1/files/upload' => [
                'path' => '/api/v1/files/upload',
                'handler' => 'closure',
                'handler_exists' => true,
            ],
            'POST /api/v1/files/upload-multiple' => [
                'path' => '/api/v1/files/upload-multiple',
                'handler' => 'closure',
                'handler_exists' => true,
            ],
        ];

        $result = OpenApiContractChecker::checkDocuments($spec, $runtimeRoutes);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            [
                'POST /api/v1/files/upload',
                'POST /api/v1/files/upload-multiple',
            ],
            $result['issues']['duplicate_operation_ids']['duplicateUpload']
        );
        $this->assertNotContains(
            'POST /api/v1/files/upload multipart/form-data',
            $result['issues']['request_body_content_missing_schema']
        );
    }

    public function testReportsAdminAiHttpTargetsMissingFromTheContract(): void
    {
        $spec = [
            'paths' => [
                '/api/v1/admin/stats' => [
                    'get' => $this->operation('adminStats'),
                ],
            ],
        ];
        $runtimeRoutes = [
            'GET /api/v1/admin/stats' => [
                'path' => '/api/v1/admin/stats',
                'handler' => 'closure',
                'handler_exists' => true,
            ],
        ];
        $adminAiConfig = [
            'managementActions' => [
                [
                    'name' => 'get_admin_stats',
                    'api' => [
                        'method' => 'GET',
                        'path' => '/api/v1/admin/stats',
                        'payloadTemplate' => [],
                    ],
                ],
                [
                    'name' => 'create_user',
                    'api' => [
                        'payloadTemplate' => [
                            'username' => null,
                            'email' => null,
                        ],
                    ],
                ],
                [
                    'name' => 'stale_http_action',
                    'api' => [
                        'method' => 'POST',
                        'path' => '/api/v1/admin/users',
                        'payloadTemplate' => [],
                    ],
                ],
            ],
        ];

        $result = OpenApiContractChecker::checkDocuments($spec, $runtimeRoutes, $adminAiConfig);

        $this->assertFalse($result['ok']);
        $this->assertSame(
            [
                'stale_http_action' => 'POST /api/v1/admin/users',
            ],
            $result['issues']['admin_ai_api_targets_missing_from_contract']
        );
    }

    public function testRuntimeCheckRestoresTemporaryEnvironmentOverrides(): void
    {
        $keys = ['DATABASE_PATH', 'DB_CONNECTION', 'DB_DATABASE', 'JWT_SECRET', 'TURNSTILE_SECRET_KEY'];
        $previousEnv = [];
        $previousServer = [];
        $previousProcess = [];
        $existedEnv = [];
        $existedServer = [];
        $existedProcess = [];
        foreach ($keys as $key) {
            $existedEnv[$key] = array_key_exists($key, $_ENV);
            $previousEnv[$key] = $_ENV[$key] ?? null;
            $existedServer[$key] = array_key_exists($key, $_SERVER);
            $previousServer[$key] = $_SERVER[$key] ?? null;
            $processValue = getenv($key);
            $existedProcess[$key] = $processValue !== false;
            $previousProcess[$key] = $processValue;

            $_ENV[$key] = 'sentinel_' . strtolower($key);
            $_SERVER[$key] = 'sentinel_server_' . strtolower($key);
            putenv($key . '=sentinel_process_' . strtolower($key));
        }

        try {
            $checker = new OpenApiContractChecker(dirname(__DIR__, 2));
            $checker->check();

            foreach ($keys as $key) {
                $this->assertSame('sentinel_' . strtolower($key), $_ENV[$key] ?? null);
                $this->assertSame('sentinel_server_' . strtolower($key), $_SERVER[$key] ?? null);
                $this->assertSame('sentinel_process_' . strtolower($key), getenv($key));
            }
        } finally {
            foreach ($keys as $key) {
                if ($existedEnv[$key]) {
                    $_ENV[$key] = $previousEnv[$key];
                } else {
                    unset($_ENV[$key]);
                }

                if ($existedServer[$key]) {
                    $_SERVER[$key] = $previousServer[$key];
                } else {
                    unset($_SERVER[$key]);
                }

                if ($existedProcess[$key]) {
                    putenv($key . '=' . $previousProcess[$key]);
                } else {
                    putenv($key);
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function operation(string $operationId, array $overrides = []): array
    {
        return array_replace_recursive([
            'operationId' => $operationId,
            'tags' => ['Diagnostics'],
            'summary' => 'Valid operation',
            'description' => 'Valid operation description.',
            'responses' => [
                '200' => [
                    'description' => 'OK',
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'object'],
                        ],
                    ],
                ],
                '400' => [
                    'description' => 'Bad request',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/ErrorResponse'],
                        ],
                    ],
                ],
            ],
        ], $overrides);
    }
}
