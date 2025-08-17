<?php

namespace CarbonTrack\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use CarbonTrack\Models\School;
use CarbonTrack\Services\AuditLogService;

class SchoolController extends BaseController
{
    protected $auditLogService;

    public function __construct($container)
    {
        $this->auditLogService = $container->get(AuditLogService::class);
    }

    // Get all schools (publicly accessible)
    public function index(Request $request, Response $response, array $args)
    {
        $schools = School::all();
        return $this->response($response, ["schools" => $schools]);
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

    // Admin: Get a single school
    public function show(Request $request, Response $response, array $args)
    {
        $school = School::find($args["id"]);
        if (!$school) {
            throw new \Exception("School not found", 404);
        }
        return $this->response($response, ["school" => $school]);
    }

    // Admin: Update an existing school
    public function update(Request $request, Response $response, array $args)
    {
        $school = School::find($args["id"]);
        if (!$school) {
            throw new \Exception("School not found", 404);
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
            throw new \Exception("School not found", 404);
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
            throw new \Exception("Soft deleted school not found", 404);
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
            throw new \Exception("School not found in trash", 404);
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
}


