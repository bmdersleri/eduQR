<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Bootstrap;
use EduQR\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test — FR-65
 *
 * Verifies Bootstrap registered the /api/v1/questions/{id}/themes route pattern.
 */
class QuestionThemesRouteTest extends TestCase
{
    public function testBootstrapRegistersQuestionThemesRoute_FR65(): void
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
            if ($route['method'] === 'GET' && $route['pattern'] === '/api/v1/questions/{id}/themes') {
                $found = true;

                break;
            }
        }

        $this->assertTrue($found, 'The /api/v1/questions/{id}/themes route must be registered.');
    }
}
