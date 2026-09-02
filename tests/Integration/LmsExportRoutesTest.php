<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Bootstrap;
use EduQR\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test — FR-98
 *
 * Verifies Bootstrap registered both LMS export route patterns, and that the
 * GIFT route still resolves ahead of the plain /report route.
 */
class LmsExportRoutesTest extends TestCase
{
    /** @return array<int,array{method:string,pattern:string}> */
    private function registeredRoutes(): array
    {
        $router = new Router();

        $reflection = new ReflectionClass(Bootstrap::class);
        $reflection->getMethod('registerRoutes')->invoke(null, $router, dirname(__DIR__, 2));

        return (new ReflectionClass(Router::class))->getProperty('routes')->getValue($router);
    }

    public function testBootstrapRegistersLmsExportRoutes_FR98(): void
    {
        $patterns = [];
        foreach ($this->registeredRoutes() as $route) {
            if ($route['method'] === 'GET') {
                $patterns[] = $route['pattern'];
            }
        }

        $this->assertContains('/api/v1/sessions/{id}/questions.gift.txt', $patterns);
        $this->assertContains('/api/v1/sessions/{id}/gradebook.csv', $patterns);
    }

    public function testGiftRouteMatchesItsOwnPathAndNotTheReportRoute_FR98(): void
    {
        $matched = null;
        foreach ($this->registeredRoutes() as $route) {
            if ($route['method'] === 'GET' && preg_match($route['regex'], '/api/v1/sessions/42/questions.gift.txt')) {
                $matched = $route['pattern'];

                break;
            }
        }

        $this->assertSame('/api/v1/sessions/{id}/questions.gift.txt', $matched);
    }
}
