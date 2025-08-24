<?php

declare(strict_types=1);

/**
 * OpenAPI Compliance Verification Script
 * 
 * This script compares the actual API routes defined in routes.php
 * with the paths defined in openapi.json to ensure compliance
 */

require_once __DIR__ . '/vendor/autoload.php';

class OpenAPIComplianceChecker
{
    private array $openApiPaths = [];
    private array $actualRoutes = [];
    private array $discrepancies = [];

    public function __construct()
    {
        $this->loadOpenApiPaths();
        $this->extractActualRoutes();
    }

    private function loadOpenApiPaths(): void
    {
        $openApiContent = file_get_contents(__DIR__ . '/openapi.json');
        $openApiData = json_decode($openApiContent, true);
        
        if (isset($openApiData['paths'])) {
            $this->openApiPaths = array_keys($openApiData['paths']);
        }
        
        echo "Loaded " . count($this->openApiPaths) . " paths from OpenAPI specification\n";
    }

    private function extractActualRoutes(): void
    {
        // This is a simplified extraction - in a real scenario, you'd want to 
        // introspect the Slim app routes programmatically
        $routesContent = file_get_contents(__DIR__ . '/src/routes.php');
        
        // Extract route patterns using regex
        $patterns = [
            '/\$\w+->get\(\s*[\'"]([^\'"]+)[\'"]/',
            '/\$\w+->post\(\s*[\'"]([^\'"]+)[\'"]/',
            '/\$\w+->put\(\s*[\'"]([^\'"]+)[\'"]/',
            '/\$\w+->delete\(\s*[\'"]([^\'"]+)[\'"]/',
            '/\$\w+->patch\(\s*[\'"]([^\'"]+)[\'"]/',
        ];
        
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        
        foreach ($patterns as $index => $pattern) {
            preg_match_all($pattern, $routesContent, $matches);
            foreach ($matches[1] as $route) {
                // Normalize route to OpenAPI format
                $normalizedRoute = $this->normalizeRoute($route);
                $this->actualRoutes[] = [
                    'method' => $methods[$index],
                    'path' => $normalizedRoute,
                    'original' => $route
                ];
            }
        }
        
        echo "Extracted " . count($this->actualRoutes) . " routes from actual implementation\n";
    }

    private function normalizeRoute(string $route): string
    {
        // Convert Slim route patterns to OpenAPI format
        // Example: /{id:[0-9]+} -> /{id}
        $normalized = preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $route);
        
        // Remove leading slash if present for comparison
        $normalized = ltrim($normalized, '/');
        
        // For API routes, remove the /api/v1 prefix to match OpenAPI format
        if (strpos($normalized, 'api/v1/') === 0) {
            $normalized = substr($normalized, 7); // Remove "api/v1/"
        }
        
        return '/' . $normalized;
    }

    public function checkCompliance(): array
    {
        $actualPaths = array_unique(array_column($this->actualRoutes, 'path'));
        
        // Find paths in OpenAPI but not in actual routes
        $missingInActual = array_diff($this->openApiPaths, $actualPaths);
        
        // Find paths in actual routes but not in OpenAPI
        $missingInOpenApi = array_diff($actualPaths, $this->openApiPaths);
        
        // Common paths
        $commonPaths = array_intersect($this->openApiPaths, $actualPaths);
        
        return [
            'total_openapi_paths' => count($this->openApiPaths),
            'total_actual_paths' => count($actualPaths),
            'common_paths' => count($commonPaths),
            'missing_in_actual' => $missingInActual,
            'missing_in_openapi' => $missingInOpenApi,
            'compliance_percentage' => round((count($commonPaths) / count($this->openApiPaths)) * 100, 2)
        ];
    }

    public function generateDetailedReport(): void
    {
        $results = $this->checkCompliance();
        
        echo "\n=== OpenAPI Compliance Report ===\n";
        echo "Total OpenAPI paths: " . $results['total_openapi_paths'] . "\n";
        echo "Total actual routes: " . $results['total_actual_paths'] . "\n";
        echo "Common paths: " . $results['common_paths'] . "\n";
        echo "Compliance percentage: " . $results['compliance_percentage'] . "%\n\n";
        
        if (!empty($results['missing_in_actual'])) {
            echo "⚠️  Paths defined in OpenAPI but missing in actual routes:\n";
            foreach ($results['missing_in_actual'] as $path) {
                echo "   - " . $path . "\n";
                $this->suggestImplementation($path);
            }
            echo "\n";
        }
        
        if (!empty($results['missing_in_openapi'])) {
            echo "⚠️  Paths implemented but missing in OpenAPI:\n";
            foreach ($results['missing_in_openapi'] as $path) {
                echo "   - " . $path . "\n";
            }
            echo "\n";
        }
        
        if (empty($results['missing_in_actual']) && empty($results['missing_in_openapi'])) {
            echo "✅ Perfect compliance! All paths match between OpenAPI and actual routes.\n";
        }
        
        echo "\n=== Detailed Route Analysis ===\n";
        $this->analyzeRoutesByController();
    }

    private function suggestImplementation(string $path): void
    {
        // Suggest which controller method might handle this path
        $pathParts = explode('/', trim($path, '/'));
        
        if (count($pathParts) >= 3 && $pathParts[0] === 'api' && $pathParts[1] === 'v1') {
            $resource = $pathParts[2];
            $controller = $this->getControllerName($resource);
            echo "     💡 Suggestion: Implement in {$controller}\n";
        }
    }

    private function getControllerName(string $resource): string
    {
        $controllerMap = [
            'auth' => 'AuthController',
            'users' => 'UserController',
            'carbon-activities' => 'CarbonActivityController',
            'carbon-track' => 'CarbonTrackController',
            'products' => 'ProductController',
            'exchange' => 'ProductController',
            'messages' => 'MessageController',
            'avatars' => 'AvatarController',
            'schools' => 'SchoolController',
            'files' => 'FileUploadController',
            'admin' => 'AdminController'
        ];
        
        return $controllerMap[$resource] ?? ucfirst($resource) . 'Controller';
    }

    private function analyzeRoutesByController(): void
    {
        $routesByController = [];
        
        foreach ($this->actualRoutes as $route) {
            $pathParts = explode('/', trim($route['path'], '/'));
            
            if (count($pathParts) >= 3 && $pathParts[0] === 'api' && $pathParts[1] === 'v1') {
                $resource = $pathParts[2];
                $controller = $this->getControllerName($resource);
                
                if (!isset($routesByController[$controller])) {
                    $routesByController[$controller] = [];
                }
                
                $routesByController[$controller][] = $route['method'] . ' ' . $route['path'];
            }
        }
        
        foreach ($routesByController as $controller => $routes) {
            echo "{$controller}:\n";
            foreach (array_unique($routes) as $route) {
                $isInOpenApi = $this->isRouteInOpenApi($route);
                $status = $isInOpenApi ? '✅' : '❌';
                echo "   {$status} {$route}\n";
            }
            echo "\n";
        }
    }

    private function isRouteInOpenApi(string $route): bool
    {
        $parts = explode(' ', $route, 2);
        if (count($parts) < 2) return false;
        
        $path = $parts[1];
        return in_array($path, $this->openApiPaths);
    }

    public function suggestOpenApiUpdates(): array
    {
        $results = $this->checkCompliance();
        $suggestions = [];
        
        foreach ($results['missing_in_openapi'] as $path) {
            $suggestions[] = [
                'action' => 'add_to_openapi',
                'path' => $path,
                'description' => "Add path '{$path}' to OpenAPI specification",
                'priority' => $this->getPathPriority($path)
            ];
        }
        
        foreach ($results['missing_in_actual'] as $path) {
            $suggestions[] = [
                'action' => 'implement_route',
                'path' => $path,
                'description' => "Implement route for '{$path}' in backend",
                'priority' => $this->getPathPriority($path)
            ];
        }
        
        // Sort by priority
        usort($suggestions, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
        
        return $suggestions;
    }

    private function getPathPriority(string $path): int
    {
        // Assign priority based on path importance
        if (strpos($path, '/auth/') !== false) return 10; // Authentication is critical
        if (strpos($path, '/users/') !== false) return 9;  // User management
        if (strpos($path, '/admin/') !== false) return 8;  // Admin features
        if (strpos($path, '/carbon-') !== false) return 7; // Core carbon tracking
        if (strpos($path, '/products') !== false) return 6; // Product features
        if (strpos($path, '/messages') !== false) return 5; // Messaging
        if (strpos($path, '/files') !== false) return 4;   // File management
        if (strpos($path, '/avatars') !== false) return 3; // Avatar features
        return 2; // Default priority
    }
}

// Main execution
echo "🔍 Starting OpenAPI Compliance Check...\n\n";

$checker = new OpenAPIComplianceChecker();
$checker->generateDetailedReport();

echo "\n=== Improvement Suggestions ===\n";
$suggestions = $checker->suggestOpenApiUpdates();

if (empty($suggestions)) {
    echo "🎉 No improvements needed! The API is fully compliant.\n";
} else {
    echo "📝 Recommended actions (sorted by priority):\n\n";
    foreach ($suggestions as $index => $suggestion) {
        echo ($index + 1) . ". {$suggestion['description']} (Priority: {$suggestion['priority']})\n";
    }
}

echo "\n✅ OpenAPI compliance check completed!\n";