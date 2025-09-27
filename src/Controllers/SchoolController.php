<?php

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Models\School;
use CarbonTrack\Services\AuditLogService;
use CarbonTrack\Services\ErrorLogService;
use PDO;
use Illuminate\Database\QueryException;

class SchoolController extends BaseController
{
    protected $auditLogService;
    protected $errorLogService;
    /** @var PDO */
    protected $db;

    private const ERR_SCHOOL_NOT_FOUND = 'School not found';
    private const CODE_SCHOOL_NOT_FOUND = 'SCHOOL_NOT_FOUND';

    public function __construct($container)
    {
        $this->auditLogService = $container->get(AuditLogService::class);
        $this->errorLogService = $container->get(ErrorLogService::class);
        // 获取 PDO 以便处理班级相关的原生查询
        $this->db = $container->get(PDO::class);
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
        } catch (QueryException $e) {
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
        $data = $request->getParsedBody();
        $this->validate($data, [
            "name" => "required|string|max:255",
            "location" => "required|string|max:255",
            "is_active" => "boolean"
        ]);

        $school = School::create($data);

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
                    } catch (QueryException $e) {
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
                    $this->logExceptionWithFallback($e, $request, 'SchoolController::create error: ' . $e->getMessage());
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

        $data = $request->getParsedBody();
        $this->validate($data, [
            "name" => "string|max:255",
            "location" => "string|max:255",
            "is_active" => "boolean"
        ]);

        $oldData = $school->toArray();
        $school->update($data);

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

        $where = 'WHERE school_id = :sid AND (deleted_at IS NULL)';
        $bind = ['sid' => $schoolId];
        if ($search !== '') {
            $where .= ' AND name LIKE :kw';
            $bind['kw'] = '%' . $search . '%';
        }

        $totalStmt = $this->db->prepare("SELECT COUNT(*) FROM school_classes $where");
        $totalStmt->execute($bind);
        $total = (int)$totalStmt->fetchColumn();

        $sql = "SELECT id, school_id, name, is_active, created_at, updated_at FROM school_classes $where ORDER BY name ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($bind as $k => $v) {
            $paramType = is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $k, $v, $paramType);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
        $name = trim((string)($data['name'] ?? ''));
    $body = null;

        if ($name === '') {
            $httpStatus = 400;
            $body = [
                'success' => false,
                'message' => 'Class name is required',
                'code' => 'MISSING_NAME'
            ];
        } else {
            // Check existing (case-insensitive)
            $check = $this->db->prepare('SELECT id, school_id, name, is_active FROM school_classes WHERE school_id = ? AND LOWER(name) = LOWER(?) AND deleted_at IS NULL LIMIT 1');
            $check->execute([$schoolId, $name]);
            $existing = $check->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($existing) {
                $httpStatus = 200;
                $body = [
                    'success' => true,
                    'data' => ['class' => $existing]
                ];
            } else {
                $ins = $this->db->prepare('INSERT INTO school_classes (school_id, name, is_active, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())');
                $ins->execute([$schoolId, $name]);
                $id = (int)$this->db->lastInsertId();

                $sel = $this->db->prepare('SELECT id, school_id, name, is_active, created_at, updated_at FROM school_classes WHERE id = ?');
                $sel->execute([$id]);
                $row = $sel->fetch(PDO::FETCH_ASSOC);

                // 审计日志
                $this->auditLogService->log(
                    $request->getAttribute('user_id'),
                    'SchoolClass',
                    $id,
                    'create',
                    'User created class for school #' . $schoolId . ': ' . $name
                );

                $httpStatus = 201;
                $body = [
                    'success' => true,
                    'data' => ['class' => $row]
                ];
            }
        }

        return $this->response($response, $body ?? [ 'success' => false, 'message' => 'Unexpected state' ], $httpStatus);
    }


    private function logExceptionWithFallback(\Throwable $exception, Request $request, string $contextMessage = ''): void
    {
        if ($this->errorLogService) {
            try {
                $extra = $contextMessage !== '' ? ['context_message' => $contextMessage] : [];
                $this->errorLogService->logException($exception, $request, $extra);
                return;
            } catch (\Throwable $loggingError) {
                error_log('ErrorLogService logging failed: ' . $loggingError->getMessage());
            }
        }
        if ($contextMessage !== '') {
            error_log($contextMessage);
        } else {
            error_log($exception->getMessage());
        }
    }

}
