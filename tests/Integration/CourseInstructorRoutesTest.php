<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Bootstrap;
use EduQR\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test — FR-97
 *
 * Verifies Bootstrap registered the course-instructor management routes.
 */
class CourseInstructorRoutesTest extends TestCase
{
    public function testBootstrapRegistersCourseInstructorRoutes_FR97(): void
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
            ['GET', '/api/v1/courses/{id}/instructors'],
            ['POST', '/api/v1/courses/{id}/instructors'],
            ['DELETE', '/api/v1/courses/{id}/instructors/{userId}'],
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

    /**
     * Guards route ordering: /courses/{id}/instructors must not be swallowed by
     * the /courses/{id} pattern registered before it.
     */
    public function testInstructorRouteIsNotShadowedByCourseShowRoute_FR97(): void
    {
        $router = new Router();
        $projectRoot = dirname(__DIR__, 2);

        (new ReflectionClass(Bootstrap::class))
            ->getMethod('registerRoutes')
            ->invoke(null, $router, $projectRoot);

        $routes = (new ReflectionClass(Router::class))->getProperty('routes')->getValue($router);

        $matched = null;
        foreach ($routes as $route) {
            if ($route['method'] === 'GET' && preg_match($route['regex'], '/api/v1/courses/12/instructors')) {
                $matched = $route['pattern'];

                break;
            }
        }

        $this->assertSame('/api/v1/courses/{id}/instructors', $matched);
    }
}
