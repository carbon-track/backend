<?php

namespace CarbonTrack\Controllers;

use CarbonTrack\Services\DatabaseService;
use Illuminate\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Models\School;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use CarbonTrack\Support\InputValueNormalizer;
use Illuminate\Database\QueryException;

class SchoolController extends BaseController
{
    use LogsClientExceptions;

    protected $auditLogService;
    protected $errorLogService;
    protected Connection $db;

    private const ERR_SCHOOL_NOT_FOUND = 'School not found';
    private const CODE_SCHOOL_NOT_FOUND = 'SCHOOL_NOT_FOUND';

    public function __construct(
        AuditLogService $auditLogService,
        ErrorLogService $errorLogService,
        Connection $db
    )
    {
        $this->auditLogService = $auditLogService;
        $this->errorLogService = $errorLogService;
        $this->db = $db;
    }

    // Get schools with optional fuzzy search and pagination (public)
    public function index(Request $request, Response $response, array $args)
    {
        $params = $request->getQueryParams();

        $limit = (int)($params['limit'] ?? 20);
        $limit = max(1, min(100, $limit));
        $page = (int)($params['page'] ?? 1);
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $query = School::query()->whereNull('deleted_at')->where('is_active', true);

        if (!empty($params['search'])) {
            $search = trim((string)$params['search']);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', '%' . $search . '%')
                  ->orWhere('location', 'LIKE', '%' . $search . '%');
            });
        }

        // 获取总数与数据
        $total = (clone $query)->count();
        // 优先按 sort_order 排序；若目标数据库缺少该列，则回退到按 name 排序
        try {
            $items = (clone $query)->orderBy('sort_order', 'asc')->offset($offset)->limit($limit)->get();
        } catch (QueryException $exception) {
            $this->logExceptionWithFallback($exception, $request, 'Retrying school ordering by name after query failure');
            $items = (clone $query)->orderBy('name', 'asc')->offset($offset)->limit($limit)->get();
        }

        return $this->response($response, [
            'success' => true,
            'data' => [
                'schools' => $items,
                'pagination' => [
                    'total_items' => $total,
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total_pages' => (int)ceil($total / $limit)
                ]
            ]
        ]);
    }

    // Admin: Get all schools with pagination and filters
    public function adminIndex(Request $request, Response $response, array $args)
    {
        $params = $request->getQueryParams();
        $query = School::query();

        if (isset($params["search"]) && !empty($params["search"])) {
            $search = "::" . $params["search"] . "::";
            $query->where(function ($q) use ($search) {
                $q->where("name", "LIKE", "%" . $search . "%")
                  ->orWhere("location", "LIKE", "%" . $search . "%");
            });
        }

        $limit = $params["limit"] ?? 10;
        $page = $params["page"] ?? 1;

        $schools = $query->paginate($limit, ["*"], "page", $page);

        return $this->response($response, [
            "data" => $schools->items(),
            "pagination" => [
                "total_items" => $schools->total(),
                "total_pages" => $schools->lastPage(),
                "current_page" => $schools->currentPage(),
                "per_page" => $schools->perPage(),
            ]
        ]);
    }

    // Admin: Create a new school
    public function store(Request $request, Response $response, array $args)
    {
        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            return $this->response($response, [
                'success' => false,
                'message' => 'Request body must be a JSON object',
                'code' => 'INVALID_REQUEST_BODY',
            ], 400);
        }

        try {
            $data = $this->sanitizeSchoolPayload($payload);
        } catch (\InvalidArgumentException $exception) {
            $this->logClientException($exception, $request, 400, __METHOD__, auditCategory: 'school_management');
            return $this->response($response, [
                'success' => false,
                'message' => DatabaseService::exceptionMessage($exception),
                'code' => 'VALIDATION_ERROR',
            ], 400);
        }

        $this->validate($data, [
            "name" => "required|string|max:255",
            "location" => "required|string|max:255",
            "is_active" => "boolean",
            "sort_order" => "integer"
        ]);

        $school = $this->createSchoolWithCompatibility($request, $data);

        $this->auditLogService->log(
            $request->getAttribute("user_id"),
            "School",
            $school->id,
            "create",
            "Created new school: " . $school->name
        );

        return $this->response($response, ["school" => $school], 201);
    }

    // Public: Create or fetch a school (authenticated users)
    public function createOrFetch(Request $request, Response $response, array $args)
    {
        $data = $request->getParsedBody();
        $name = trim((string)($data['name'] ?? ''));
        $location = isset($data['location']) ? trim((string)$data['location']) : null;

        $httpStatus = 200;
        $payload = null;

        if ($name === '') {
            $httpStatus = 400;
            $payload = [
                'success' => false,
                'message' => 'School name is required',
                'code' => 'MISSING_NAME'
            ];
        } else {
            // 查找同名（不区分大小写）学校
            $existing = School::whereRaw('LOWER(name) = LOWER(?)', [$name])
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                $httpStatus = 200;
                $payload = [
                    'success' => true,
                    'data' => ['school' => $existing]
                ];
            } else {
                // 兼容缺少 sort_order 列的数据库：优先携带 sort_order 创建，失败则回退为不带该字段
                try {
                    try {
                        $school = School::create([
                            'name' => $name,
                            'location' => $location,
                            'is_active' => true,
                            'sort_order' => 0
                        ]);
                    } catch (QueryException $exception) {
                        $this->logExceptionWithFallback($exception, $request, 'Retrying school insert without sort_order');
                        $school = School::create([
                            'name' => $name,
                            'location' => $location,
                            'is_active' => true
                        ]);
                    }

                    // 记录审计
                    $this->auditLogService->log(
                        $request->getAttribute('user_id'),
                        'School',
                        $school->id,
                        'create',
                        'User created school (public): ' . $school->name
                    );

                    $httpStatus = 201;
                    $payload = [
                        'success' => true,
                        'data' => ['school' => $school]
                    ];
                } catch (\Throwable $e) {
                    $this->logExceptionWithFallback($e, $request, 'SchoolController::create error: ' . DatabaseService::exceptionMessage($e));
                    $httpStatus = 500;
                    $payload = [
                        'success' => false,
                        'message' => 'Failed to create school'
                    ];
                }
            }
        }

        return $this->response($response, $payload ?? ['success' => false], $httpStatus);
    }
    
    // Admin: Get a single school
    public function show(Request $request, Response $response, array $args)
    {
        $school = School::find($args["id"]);
        if (!$school) {
            return $this->response($response, [
                'success' => false,
                'message' => self::ERR_SCHOOL_NOT_FOUND,
                'code' => self::CODE_SCHOOL_NOT_FOUND
            ], 404);
        }
        return $this->response($response, ["school" => $school]);
    }

    // Admin: Update an existing school
    public function update(Request $request, Response $response, array $args)
    {
        $school = School::find($args["id"]);
        if (!$school) {
            return $this->response($response, [
                'success' => false,
                'message' => self::ERR_SCHOOL_NOT_FOUND,
                'code' => self::CODE_SCHOOL_NOT_FOUND
            ], 404);
        }

        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            return $this->response($response, [
                'success' => false,
                'message' => 'Request body must be a JSON object',
                'code' => 'INVALID_REQUEST_BODY',
            ], 400);
        }

        try {
            $data = $this->sanitizeSchoolPayload($payload);
        } catch (\InvalidArgumentException $exception) {
            $this->logClientException($exception, $request, 400, __METHOD__, auditCategory: 'school_management');
            return $this->response($response, [
                'success' => false,
                'message' => DatabaseService::exceptionMessage($exception),
                'code' => 'VALIDATION_ERROR',
            ], 400);
        }

        $this->validate($data, [
            "name" => "string|max:255",
            "location" => "string|max:255",
            "is_active" => "boolean",
            "sort_order" => "integer"
        ]);

        $oldData = $school->toArray();
        $this->updateSchoolWithCompatibility($request, $school, $data);

        $this->auditLogService->log(
            $request->getAttribute("user_id"),
            "School",
            $school->id,
            "update",
            "Updated school: " . $school->name,
            json_encode(array_diff_assoc($school->toArray(), $oldData))
        );

        return $this->response($response, ["school" => $school]);
    }

    // Admin: Soft delete a school
    public function delete(Request $request, Response $response, array $args)
    {
        $school = School::find($args["id"]);
        if (!$school) {
            return $this->response($response, [
                'success' => false,
                'message' => self::ERR_SCHOOL_NOT_FOUND,
                'code' => self::CODE_SCHOOL_NOT_FOUND
            ], 404);
        }

        $school->delete(); // Soft delete

        $this->auditLogService->log(
            $request->getAttribute("user_id"),
            "School",
            $school->id,
            "delete",
            "Soft deleted school: " . $school->name
        );

        return $this->response($response, ["message" => "School soft deleted successfully"]);
    }

    // Admin: Restore a soft deleted school
    public function restore(Request $request, Response $response, array $args)
    {
        $school = School::onlyTrashed()->find($args["id"]);
        if (!$school) {
            return $this->response($response, [
                'success' => false,
                'message' => 'Soft deleted school not found',
                'code' => self::CODE_SCHOOL_NOT_FOUND
            ], 404);
        }

        $school->restore();

        $this->auditLogService->log(
            $request->getAttribute("user_id"),
            "School",
            $school->id,
            "restore",
            "Restored school: " . $school->name
        );

        return $this->response($response, ["message" => "School restored successfully"]);
    }

    // Admin: Permanently delete a school
    public function forceDelete(Request $request, Response $response, array $args)
    {
        $school = School::onlyTrashed()->find($args["id"]);
        if (!$school) {
            return $this->response($response, [
                'success' => false,
                'message' => 'School not found in trash',
                'code' => self::CODE_SCHOOL_NOT_FOUND
            ], 404);
        }

        $school->forceDelete();

        $this->auditLogService->log(
            $request->getAttribute("user_id"),
            "School",
            $school->id,
            "force_delete",
            "Permanently deleted school: " . $school->name
        );

        return $this->response($response, ["message" => "School permanently deleted successfully"]);
    }

    // Admin: Get school statistics
    public function stats(Request $request, Response $response, array $args)
    {
        $totalSchools = School::count();
        $activeSchools = School::where("is_active", true)->count();
        $inactiveSchools = School::where("is_active", false)->count();
        $deletedSchools = School::onlyTrashed()->count();

        return $this->response($response, [
            "total_schools" => $totalSchools,
            "active_schools" => $activeSchools,
            "inactive_schools" => $inactiveSchools,
            "deleted_schools" => $deletedSchools,
        ]);
    }

    // Public: List classes for a school with optional fuzzy search and pagination
    public function listClasses(Request $request, Response $response, array $args)
    {
        $schoolId = (int)($args['id'] ?? 0);
        if ($schoolId <= 0) {
            return $this->response($response, [
                'success' => false,
                'message' => 'Invalid school id',
                'code' => 'INVALID_SCHOOL_ID'
            ], 400);
        }

        // Ensure school exists
        $exists = School::where('id', $schoolId)->whereNull('deleted_at')->exists();
        if (!$exists) {
            return $this->response($response, [
                'success' => false,
                'message' => self::ERR_SCHOOL_NOT_FOUND,
                'code' => self::CODE_SCHOOL_NOT_FOUND
            ], 404);
        }

        $params = $request->getQueryParams();
        $limit = (int)($params['limit'] ?? 20);
        $limit = max(1, min(100, $limit));
        $page = (int)($params['page'] ?? 1);
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;
        $search = trim((string)($params['search'] ?? ''));

        $classes = $this->db->table('school_classes')
            ->where('school_id', $schoolId)
            ->whereNull('deleted_at');
        if ($search !== '') {
            $classes->where('name', 'like', '%' . $search . '%');
        }

        $total = $classes->count();
        $items = $classes->orderBy('name')->limit($limit)->offset($offset)
            ->get(['id', 'school_id', 'name', 'is_active', 'created_at', 'updated_at'])->all();

        return $this->response($response, [
            'success' => true,
            'data' => [
                'classes' => $items,
                'pagination' => [
                    'total_items' => $total,
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total_pages' => (int)ceil($total / $limit)
                ]
            ]
        ]);
    }

    // Authenticated: Create a class for a school (idempotent by name)
    public function createClass(Request $request, Response $response, array $args)
    {
        $schoolId = (int)($args['id'] ?? 0);
        if ($schoolId <= 0) {
            return $this->response($response, [
                'success' => false,
                'message' => 'Invalid school id',
                'code' => 'INVALID_SCHOOL_ID'
            ], 400);
        }

        // Ensure school exists
        $exists = School::where('id', $schoolId)->whereNull('deleted_at')->exists();
        if (!$exists) {
            return $this->response($response, [
                'success' => false,
                'message' => self::ERR_SCHOOL_NOT_FOUND,
                'code' => self::CODE_SCHOOL_NOT_FOUND
            ], 404);
        }

        $data = $request->getParsedBody();
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            return $this->response($response, [
                'success' => false,
                'message' => 'Class name is required',
                'code' => 'MISSING_NAME',
            ], 400);
        }

        $existing = $this->db->table('school_classes')
            ->where('school_id', $schoolId)
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->whereNull('deleted_at')
            ->first(['id', 'school_id', 'name', 'is_active']);
        if ($existing !== null) {
            return $this->response($response, [
                'success' => true,
                'data' => ['class' => (array) $existing],
            ], 200);
        }

        $class = $this->db->transaction(function () use ($schoolId, $name): array {
            $classes = $this->db->table('school_classes');
            $now = $this->db->raw('CURRENT_TIMESTAMP');
            $id = $classes->insertGetId([
                'school_id' => $schoolId,
                'name' => $name,
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $created = $classes->find($id, ['id', 'school_id', 'name', 'is_active', 'created_at', 'updated_at']);
            if ($created === null) {
                throw new \RuntimeException('The newly created class could not be loaded.');
            }

            return (array) $created;
        });

        $this->auditLogService->log([
            'user_id' => $request->getAttribute('user_id'),
            'action' => 'school_class_created',
            'operation_category' => 'school',
            'affected_table' => 'school_classes',
            'affected_id' => (int) $class['id'],
            'new_data' => $class,
            'status' => 'success',
        ]);

        return $this->response($response, [
            'success' => true,
            'data' => ['class' => $class],
        ], 201);
    }


    private function logExceptionWithFallback(\Throwable $exception, Request $request, string $contextMessage = ''): void
    {
        if ($this->errorLogService) {
            try {
                $extra = $contextMessage !== '' ? ['context_message' => $contextMessage] : [];
                $this->errorLogService->logException($exception, $request, $extra);
                return;
            } catch (\Throwable $loggingError) { // nosemgrep: semgrep.enforce-errorlogservice-in-catch
                // Use the emergency log if ErrorLogService itself fails.
                error_log('ErrorLogService logging failed: ' . DatabaseService::exceptionMessage($loggingError));
            }
        }
        if ($contextMessage !== '') {
            error_log($contextMessage);
        } else {
            error_log(DatabaseService::exceptionMessage($exception));
        }
    }

    /**
     * @param mixed $payload
     * @return array<string,mixed>
     */
    private function sanitizeSchoolPayload(mixed $payload): array
    {
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Request body must be a JSON object');
        }

        if (array_key_exists('is_active', $payload)) {
            $payload['is_active'] = InputValueNormalizer::boolean($payload['is_active'], 'is_active');
        }

        if (array_key_exists('sort_order', $payload)) {
            $payload['sort_order'] = InputValueNormalizer::integer($payload['sort_order'], 'sort_order');
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function createSchoolWithCompatibility(Request $request, array $data): School
    {
        try {
            return School::create($data);
        } catch (QueryException $exception) {
            if (!$this->shouldRetryWithoutSortOrder($data, $exception)) {
                throw $exception;
            }

            $this->logExceptionWithFallback($exception, $request, 'Retrying school insert without sort_order');
            return School::create($this->withoutSortOrder($data));
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function updateSchoolWithCompatibility(Request $request, School $school, array $data): void
    {
        if ($data === []) {
            return;
        }

        try {
            $school->update($data);
        } catch (QueryException $exception) {
            if (!$this->shouldRetryWithoutSortOrder($data, $exception)) {
                throw $exception;
            }

            $this->logExceptionWithFallback($exception, $request, 'School update failed with sort_order');
            $retryData = $this->withoutSortOrder($data);
            if ($retryData !== []) {
                $school->update($retryData);
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function shouldRetryWithoutSortOrder(array $data, QueryException $exception): bool
    {
        if (!array_key_exists('sort_order', $data)) {
            return false;
        }

        $message = strtolower(DatabaseService::exceptionMessage($exception));
        if (!str_contains($message, 'sort_order')) {
            return false;
        }

        foreach (['unknown column', 'no such column', 'has no column named', 'undefined column'] as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function withoutSortOrder(array $data): array
    {
        unset($data['sort_order']);

        return $data;
    }

}
