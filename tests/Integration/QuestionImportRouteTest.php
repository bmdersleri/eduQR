<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Bootstrap;
use EduQR\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test — FR-30
 *
 * Verifies Bootstrap registered the /api/v1/sessions/{id}/questions/import route pattern.
 */
class QuestionImportRouteTest extends TestCase
{
    public function testBootstrapRegistersQuestionImportRoute_FR30(): void
    {
        $router = new Router();
        $projectRoot = dirname(__DIR__, 2);

        $reflection = new ReflectionClass(Bootstrap::class);
        $method = $reflection->getMethod('registerRoutes');
        $method->invoke(null, $router, $projectRoot);

        $routerReflection = new ReflectionClass(Router::class);
        $routesProperty = $routerReflection->getProperty('routes');
        $routes = $routesProperty->getValue($router);

        $found = false;
        foreach ($routes as $route) {
            if ($route['method'] === 'POST' && $route['pattern'] === '/api/v1/sessions/{id}/questions/import') {
                $found = true;

                break;
            }
        }

        $this->assertTrue($found, 'The /api/v1/sessions/{id}/questions/import route must be registered.');
    }
}
