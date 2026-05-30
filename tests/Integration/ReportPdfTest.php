<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Router;
use EduQR\Bootstrap;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test — T-1102
 *
 * Verifies Bootstrap registered the /api/v1/sessions/{id}/report.pdf route pattern.
 */
class ReportPdfTest extends TestCase
{
    public function testBootstrapRegistersReportPdfRoute(): void
    {
        $router = new Router();
        $projectRoot = dirname(__DIR__, 2);

        $reflection = new ReflectionClass(Bootstrap::class);
        $method = $reflection->getMethod('registerRoutes');
        $method->setAccessible(true);
        $method->invoke(null, $router, $projectRoot);

        $routerReflection = new ReflectionClass(Router::class);
        $routesProperty = $routerReflection->getProperty('routes');
        $routesProperty->setAccessible(true);
        $routes = $routesProperty->getValue($router);

        $found = false;
        foreach ($routes as $route) {
            if ($route['method'] === 'GET' && $route['pattern'] === '/api/v1/sessions/{id}/report.pdf') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'The /api/v1/sessions/{id}/report.pdf route must be registered.');
    }
}
