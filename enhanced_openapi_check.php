<?php

declare(strict_types=1);

/**
 * Enhanced OpenAPI Compliance Verification Script
 * 
 * This script provides a more accurate comparison between OpenAPI specs and actual routes
 * by actually introspecting the Slim application routes
 */

require_once __DIR__ . '/vendor/autoload.php';

use Slim\App;
use DI\Container;

class EnhancedOpenAPIChecker
{
    private array $openApiPaths = [];
    private array $actualRoutes = [];
    private App $app;

    public function __construct()
    {
        $this->loadOpenApiPaths();
        $this->initializeApp();
        $this->extractActualRoutes();
    }

    private function loadOpenApiPaths(): void
    {
        $openApiContent = file_get_contents(__DIR__ . '/openapi.json');
        $openApiData = json_decode($openApiContent, true);
        
        $this->openApiPaths = [];
        if (isset($openApiData['paths'])) {
            foreach ($openApiData['paths'] as $path => $methods) {
                foreach ($methods as $method => $details) {
                    if (in_array(strtoupper($method), ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'])) {
                        $this->openApiPaths[] = [
                            'method' => strtoupper($method),
                            'path' => $path
                        ];
                    }
                }
            }
        }
        
        echo "Loaded " . count($this->openApiPaths) . " path-method combinations from OpenAPI specification\n";
    }

    private function initializeApp(): void
    {
        // Set up minimal environment for app initialization
        $_ENV['DATABASE_PATH'] = __DIR__ . '/test.db';
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = $_ENV['DATABASE_PATH'];
        $_ENV['JWT_SECRET'] = 'test_secret';
        $_ENV['TURNSTILE_SECRET_KEY'] = 'test_turnstile';

        try {
            $container = new Container();
            require __DIR__ . '/src/dependencies.php';

            $this->app = \Slim\Factory\AppFactory::createFromContainer($container);
            $this->app->addRoutingMiddleware();
            
            // Load routes
            $routes = require __DIR__ . '/src/routes.php';
            $routes($this->app);
            
        } catch (\Exception $e) {
            echo "Warning: Could not initialize full app, using route file parsing fallback\n";
            $this->app = null;
        }
    }

    private function extractActualRoutes(): void
    {
        $this->actualRoutes = [];
        
        if ($this->app) {
            // Extract routes from the actual Slim app
            $routeCollector = $this->app->getRouteCollector();
            $routes = $routeCollector->getRoutes();
            
            foreach ($routes as $route) {
                $methods = $route->getMethods();
                $pattern = $route->getPattern();
                
                foreach ($methods as $method) {
                    $normalizedPath = $this->normalizeActualRoute($pattern);
                    $this->actualRoutes[] = [
                        'method' => strtoupper($method),
                        'path' => $normalizedPath,
                        'original' => $pattern
                    ];
                }
            }
        } else {
            // Fallback to file parsing
            $this->extractRoutesFromFile();
        }
        
        echo "Extracted " . count($this->actualRoutes) . " actual routes from backend\n";
    }

    private function extractRoutesFromFile(): void
    {
        $routesContent = file_get_contents(__DIR__ . '/src/routes.php');
        
        // More sophisticated regex patterns
        $patterns = [
            'GET' => '/\$\w+->get\(\s*[\'"]([^\'"]+)[\'"]/',
            'POST' => '/\$\w+->post\(\s*[\'"]([^\'"]+)[\'"]/',
            'PUT' => '/\$\w+->put\(\s*[\'"]([^\'"]+)[\'"]/',
            'DELETE' => '/\$\w+->delete\(\s*[\'"]([^\'"]+)[\'"]/',
            'PATCH' => '/\$\w+->patch\(\s*[\'"]([^\'"]+)[\'"]/',
        ];
        
        foreach ($patterns as $method => $pattern) {
            preg_match_all($pattern, $routesContent, $matches);
            foreach ($matches[1] as $route) {
                $normalizedPath = $this->normalizeActualRoute($route);
                $this->actualRoutes[] = [
                    'method' => $method,
                    'path' => $normalizedPath,
                    'original' => $route
                ];
            }
        }
    }

    private function normalizeActualRoute(string $route): string
    {
        // Convert Slim route patterns to OpenAPI format
        $normalized = preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $route);
        
        // Handle API v1 prefix
        if (strpos($normalized, '/api/v1') === 0) {
            $normalized = substr($normalized, 7); // Remove "/api/v1"
        }
        
        // Ensure leading slash
        if (!str_starts_with($normalized, '/')) {
            $normalized = '/' . $normalized;
        }
        
        return $normalized;
    }

    public function generateComplianceReport(): array
    {
        $openApiRoutes = [];
        $actualRouteMap = [];
        
        // Create comparable route signatures
        foreach ($this->openApiPaths as $route) {
            $signature = $route['method'] . ' ' . $route['path'];
            $openApiRoutes[] = $signature;
        }
        
        foreach ($this->actualRoutes as $route) {
            $signature = $route['method'] . ' ' . $route['path'];
            $actualRouteMap[$signature] = $route;
        }
        
        $actualRoutes = array_keys($actualRouteMap);
        
        // Find discrepancies
        $missingInActual = array_diff($openApiRoutes, $actualRoutes);
        $missingInOpenApi = array_diff($actualRoutes, $openApiRoutes);
        $commonRoutes = array_intersect($openApiRoutes, $actualRoutes);
        
        return [
            'openapi_total' => count($openApiRoutes),
            'actual_total' => count($actualRoutes),
            'common_routes' => count($commonRoutes),
            'missing_in_actual' => $missingInActual,
            'missing_in_openapi' => $missingInOpenApi,
            'compliance_rate' => count($openApiRoutes) > 0 ? round((count($commonRoutes) / count($openApiRoutes)) * 100, 2) : 0,
            'coverage_rate' => count($actualRoutes) > 0 ? round((count($commonRoutes) / count($actualRoutes)) * 100, 2) : 0
        ];
    }

    public function printDetailedReport(): void
    {
        $report = $this->generateComplianceReport();
        
        echo "\n=== Enhanced OpenAPI Compliance Report ===\n";
        echo "OpenAPI defined routes: " . $report['openapi_total'] . "\n";
        echo "Actually implemented routes: " . $report['actual_total'] . "\n";
        echo "Matching routes: " . $report['common_routes'] . "\n";
        echo "OpenAPI compliance rate: " . $report['compliance_rate'] . "%\n";
        echo "Implementation coverage rate: " . $report['coverage_rate'] . "%\n\n";
        
        if (!empty($report['missing_in_actual'])) {
            echo "🚨 Routes defined in OpenAPI but NOT implemented:\n";
            foreach ($report['missing_in_actual'] as $route) {
                echo "   ❌ {$route}\n";
            }
            echo "\n";
        }
        
        if (!empty($report['missing_in_openapi'])) {
            echo "📝 Routes implemented but NOT documented in OpenAPI:\n";
            foreach ($report['missing_in_openapi'] as $route) {
                echo "   ⚠️  {$route}\n";
            }
            echo "\n";
        }
        
        if (empty($report['missing_in_actual']) && empty($report['missing_in_openapi'])) {
            echo "🎉 Perfect compliance! All routes match between OpenAPI and implementation.\n\n";
        }
        
        // Categorize by functionality
        $this->categorizeDiscrepancies($report);
    }

    private function categorizeDiscrepancies(array $report): void
    {
        echo "=== Discrepancy Analysis by Category ===\n";
        
        $categories = [
            'Authentication' => ['auth'],
            'User Management' => ['users'],
            'Carbon Tracking' => ['carbon'],
            'Product & Exchange' => ['products', 'exchange'],
            'Admin Functions' => ['admin'],
            'File Management' => ['files'],
            'Messaging' => ['messages'],
            'Avatars' => ['avatars'],
            'Other' => []
        ];
        
        foreach ($categories as $categoryName => $keywords) {
            echo "\n{$categoryName}:\n";
            
            $categoryMissing = [];
            $categoryExtra = [];
            
            foreach ($report['missing_in_actual'] as $route) {
                if ($this->routeMatchesCategory($route, $keywords)) {
                    $categoryMissing[] = $route;
                }
            }
            
            foreach ($report['missing_in_openapi'] as $route) {
                if ($this->routeMatchesCategory($route, $keywords)) {
                    $categoryExtra[] = $route;
                }
            }
            
            if (!empty($categoryMissing)) {
                echo "  Missing implementations:\n";
                foreach ($categoryMissing as $route) {
                    echo "    ❌ {$route}\n";
                }
            }
            
            if (!empty($categoryExtra)) {
                echo "  Undocumented implementations:\n";
                foreach ($categoryExtra as $route) {
                    echo "    📝 {$route}\n";
                }
            }
            
            if (empty($categoryMissing) && empty($categoryExtra)) {
                echo "  ✅ All routes in this category are properly aligned\n";
            }
        }
    }

    private function routeMatchesCategory(string $route, array $keywords): bool
    {
        if (empty($keywords)) {
            // "Other" category - check if it doesn't match any specific category
            $allKeywords = ['auth', 'users', 'carbon', 'products', 'exchange', 'admin', 'files', 'messages', 'avatars'];
            foreach ($allKeywords as $keyword) {
                if (stripos($route, $keyword) !== false) {
                    return false;
                }
            }
            return true;
        }
        
        foreach ($keywords as $keyword) {
            if (stripos($route, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    public function generateFixSuggestions(): array
    {
        $report = $this->generateComplianceReport();
        $suggestions = [];
        
        foreach ($report['missing_in_actual'] as $route) {
            $parts = explode(' ', $route, 2);
            $method = $parts[0];
            $path = $parts[1] ?? '';
            
            $suggestions[] = [
                'type' => 'implement',
                'priority' => $this->getPriority($path),
                'route' => $route,
                'suggestion' => "Implement {$method} {$path} in the backend",
                'controller' => $this->suggestController($path)
            ];
        }
        
        foreach ($report['missing_in_openapi'] as $route) {
            $suggestions[] = [
                'type' => 'document',
                'priority' => $this->getPriority(explode(' ', $route, 2)[1] ?? ''),
                'route' => $route,
                'suggestion' => "Add {$route} to OpenAPI specification",
                'controller' => null
            ];
        }
        
        // Sort by priority
        usort($suggestions, function($a, $b) {
            return $b['priority'] <=> $a['priority'];
        });
        
        return $suggestions;
    }

    private function getPriority(string $path): int
    {
        if (stripos($path, 'auth') !== false) return 10;
        if (stripos($path, 'users') !== false) return 9;
        if (stripos($path, 'admin') !== false) return 8;
        if (stripos($path, 'carbon') !== false) return 7;
        if (stripos($path, 'products') !== false || stripos($path, 'exchange') !== false) return 6;
        if (stripos($path, 'messages') !== false) return 5;
        if (stripos($path, 'files') !== false) return 4;
        return 3;
    }

    private function suggestController(string $path): string
    {
        if (stripos($path, 'auth') !== false) return 'AuthController';
        if (stripos($path, 'users') !== false) return 'UserController';
        if (stripos($path, 'admin') !== false) return 'AdminController';
        if (stripos($path, 'carbon') !== false) return 'CarbonTrackController or CarbonActivityController';
        if (stripos($path, 'products') !== false || stripos($path, 'exchange') !== false) return 'ProductController';
        if (stripos($path, 'messages') !== false) return 'MessageController';
        if (stripos($path, 'files') !== false) return 'FileUploadController';
        if (stripos($path, 'avatars') !== false) return 'AvatarController';
        return 'Unknown';
    }
}

// Main execution
echo "🔍 Starting Enhanced OpenAPI Compliance Check...\n\n";

$checker = new EnhancedOpenAPIChecker();
$checker->printDetailedReport();

echo "\n=== Action Items ===\n";
$suggestions = $checker->generateFixSuggestions();

if (empty($suggestions)) {
    echo "🎉 No action items needed! Perfect compliance achieved.\n";
} else {
    echo "📋 Recommended actions (prioritized):\n\n";
    foreach (array_slice($suggestions, 0, 20) as $index => $suggestion) { // Show top 20
        $icon = $suggestion['type'] === 'implement' ? '🔧' : '📝';
        echo ($index + 1) . ". {$icon} {$suggestion['suggestion']} (Priority: {$suggestion['priority']})";
        if ($suggestion['controller']) {
            echo " → {$suggestion['controller']}";
        }
        echo "\n";
    }
    
    if (count($suggestions) > 20) {
        echo "\n... and " . (count($suggestions) - 20) . " more items.\n";
    }
}

echo "\n✅ Enhanced OpenAPI compliance check completed!\n";