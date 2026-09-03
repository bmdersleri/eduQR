<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Bootstrap;
use EduQR\Config;
use EduQR\I18n\I18nService;
use EduQR\Router;
use EduQR\Support\Url;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Integration test — FR-75 / NFR-31
 *
 * The privacy-notice link is rendered on every student-facing page by
 * templates/partials/privacy-notice.php. Until T-1125 the target route was
 * never registered, so the link returned 404. These tests pin the route down.
 */
final class PrivacyRouteTest extends TestCase
{
    private function buildRouter(): Router
    {
        $router = new Router();
        $projectRoot = dirname(__DIR__, 2);

        $reflection = new ReflectionClass(Bootstrap::class);
        $method = $reflection->getMethod('registerRoutes');
        $method->invoke(null, $router, $projectRoot);

        return $router;
    }

    public function testBootstrapRegistersPrivacyRoute_FR75(): void
    {
        $routerReflection = new ReflectionClass(Router::class);
        $routesProperty = $routerReflection->getProperty('routes');
        $routes = $routesProperty->getValue($this->buildRouter());

        $found = false;
        foreach ($routes as $route) {
            if ($route['method'] === 'GET' && $route['pattern'] === '/privacy') {
                $found = true;

                break;
            }
        }

        $this->assertTrue($found, 'The GET route /privacy must be registered.');
    }

    public function testPrivacyPageRendersWithoutAuthentication_FR75(): void
    {
        // No instructor session, no participant cookie — a student reaches this
        // page from the join screen before they have either.
        unset($_COOKIE['eduqr_session'], $_COOKIE['eduqr_participant']);

        I18nService::init(dirname(__DIR__, 2) . '/locales', 'en');

        // Seed a non-200 code so that a 200 afterwards can only come from the handler.
        http_response_code(500);

        ob_start();
        $this->buildRouter()->dispatch('GET', '/privacy');
        $html = (string) ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString(I18nService::translate('privacy.page.title'), $html);
        $this->assertStringContainsString(I18nService::translate('privacy.notice.body'), $html);
        $this->assertStringContainsString(I18nService::translate('privacy.page.review.title'), $html);
    }

    public function testPrivacyLinkTargetMatchesTheRegisteredRoute_FR75(): void
    {
        // The whole point of T-1125: the URL the partial builds must be the URL
        // the router answers.
        I18nService::init(dirname(__DIR__, 2) . '/locales', 'en');

        ob_start();
        include dirname(__DIR__, 2) . '/templates/partials/privacy-notice.php';
        $partial = (string) ob_get_clean();

        $this->assertStringContainsString('href="' . eduqr_path('/privacy') . '"', $partial);

        http_response_code(500);

        ob_start();
        $this->buildRouter()->dispatch('GET', eduqr_path('/privacy'));
        $html = (string) ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString(I18nService::translate('privacy.page.title'), $html);
    }

    public function testPrivacyLinkResolvesUnderADeploymentBasePath_FR75(): void
    {
        // eduqr_path() prefixes the APP_URL base path, so a sub-directory
        // deployment must still land on the same route (NFR-15).
        $this->withAppUrl('http://example.test/eduqr', function (): void {
            I18nService::init(dirname(__DIR__, 2) . '/locales', 'en');

            ob_start();
            include dirname(__DIR__, 2) . '/templates/partials/privacy-notice.php';
            $partial = (string) ob_get_clean();

            $this->assertStringContainsString('href="/eduqr/privacy"', $partial);

            http_response_code(500);

            ob_start();
            $this->buildRouter()->dispatch('GET', eduqr_path('/privacy'));
            $html = (string) ob_get_clean();

            $this->assertSame(200, http_response_code());
            $this->assertStringContainsString(I18nService::translate('privacy.page.title'), $html);
        });
    }

    /** Run $callback with APP_URL temporarily overridden, mirroring RouterTest. */
    private function withAppUrl(string $appUrl, callable $callback): void
    {
        $ref = new ReflectionClass(Config::class);
        $data = $ref->getProperty('data');
        $originalData = $data->getValue();

        Url::reset();
        $data->setValue(null, array_merge($originalData, ['APP_URL' => $appUrl]));

        try {
            $callback();
        } finally {
            $data->setValue(null, $originalData);
            Url::reset();
        }
    }
}
