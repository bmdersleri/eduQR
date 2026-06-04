<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Bootstrap;
use EduQR\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test — FR-91
 *
 * Verifies Bootstrap registered the admin audit-log viewer routes.
 */
final class AuditLogRoutesTest extends TestCase
{
    public function testBootstrapRegistersAuditLogRoutes_FR91(): void
    {
        $router = new Router();
        $projectRoot = dirname(__DIR__, 2);

        $reflection = new ReflectionClass(Bootstrap::class);
        $method = $reflection->getMethod('registerRoutes');
        $method->invoke(null, $router, $projectRoot);

        $routerReflection = new ReflectionClass(Router::class);
        $routesProperty = $routerReflection->getProperty('routes');
        $routes = $routesProperty->getValue($router);

        $patterns = [
            ['GET', '/admin/audit-logs'],
            ['GET', '/api/v1/audit-logs'],
        ];

        foreach ($patterns as [$methodName, $pattern]) {
            $found = false;
            foreach ($routes as $route) {
                if ($route['method'] === $methodName && $route['pattern'] === $pattern) {
                    $found = true;

                    break;
                }
            }

            $this->assertTrue($found, sprintf('The %s route %s must be registered.', $methodName, $pattern));
        }
    }
}
