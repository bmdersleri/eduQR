<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Bootstrap;
use EduQR\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test — FR-93, FR-94, FR-95
 *
 * Verifies Bootstrap registered the question bank and lecture-note generation routes.
 */
class QuestionBankRoutesTest extends TestCase
{
    public function testBootstrapRegistersQuestionBankRoutes_FR93_FR94_FR95(): void
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
            ['GET', '/api/v1/courses/{id}/question-bank'],
            ['POST', '/api/v1/courses/{id}/question-bank/generate'],
            ['POST', '/api/v1/questions/{id}/bank'],
            ['POST', '/api/v1/sessions/{id}/questions/from-bank'],
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
