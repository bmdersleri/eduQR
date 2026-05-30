<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private function captureDispatch(Router $router, string $method, string $uri): string
    {
        ob_start();
        $router->dispatch($method, $uri);

        return ob_get_clean();
    }

    public function testMatchesSimpleGetRoute(): void
    {
        $router = new Router();
        $router->get('/', fn ($p) => print('home'));
        $this->assertSame('home', $this->captureDispatch($router, 'GET', '/'));
    }

    public function testExtractsNamedParameter(): void
    {
        $router = new Router();
        $router->get('/sessions/{id}', fn ($p) => print($p['id']));
        $this->assertSame('42', $this->captureDispatch($router, 'GET', '/sessions/42'));
    }

    public function testCallsNotFoundHandlerOnMiss(): void
    {
        $router = new Router();
        $router->setNotFound(fn ($p) => print('404'));
        $this->assertSame('404', $this->captureDispatch($router, 'GET', '/no-such-route'));
    }

    public function testIgnoresTrailingSlash(): void
    {
        $router = new Router();
        $router->get('/courses', fn ($p) => print('courses'));
        $this->assertSame('courses', $this->captureDispatch($router, 'GET', '/courses/'));
    }

    public function testStripsLocalePrefix(): void
    {
        $router = new Router();
        $router->get('/', fn ($p) => print($p['_locale'] ?? 'none'));
        $this->assertSame('tr', $this->captureDispatch($router, 'GET', '/tr/'));
    }
}
