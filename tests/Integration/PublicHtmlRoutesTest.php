<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Bootstrap;
use EduQR\Controllers\HtmlController;
use EduQR\Controllers\Public\AuthPageController;
use EduQR\Controllers\Public\PageController;
use EduQR\I18n\I18nService;
use EduQR\Router;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The five service-free HTML routes, now behind controllers (NFR-81).
 *
 * `/`, `/privacy`, `/login`, `/forgot-password` and `/reset-password/{token}`
 * used to be router closures that included a template which then fetched its
 * own CSRF token and included its own layout. These tests pin both halves of
 * the move: that the pages still answer 200 with their own content, and that
 * the templates no longer do any of the work the controller now does.
 *
 * @requirement NFR-81
 */
final class PublicHtmlRoutesTest extends TestCase
{
    private const CSRF = 'fixed-csrf-token-for-this-test';

    protected function setUp(): void
    {
        I18nService::init(\dirname(__DIR__, 2) . '/locales', 'en');

        // Pin the double-submit cookie so the token in the rendered form is
        // something the test can name.
        $_COOKIE['csrf_token'] = self::CSRF;
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['csrf_token']);
    }

    // ── The routes answer ─────────────────────────────────────────────────────

    public function testHomeRendersThroughItsController_NFR81(): void
    {
        $html = $this->dispatch('/');

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString(I18nService::translate('home.feature.qr_title'), $html);
        $this->assertStringContainsString(
            '<title>' . I18nService::translate('app.name') . ' — ' . I18nService::translate('app.subtitle') . '</title>',
            $html,
        );
    }

    public function testPrivacyRendersThroughItsController_NFR81(): void
    {
        $html = $this->dispatch('/privacy');

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString(I18nService::translate('privacy.page.collected.nickname'), $html);
    }

    public function testLoginRendersWithTheTokenTheControllerResolved_NFR81(): void
    {
        $html = $this->dispatch('/login');

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('value="' . self::CSRF . '"', $html);
    }

    public function testForgotPasswordRendersWithTheTokenTheControllerResolved_NFR81(): void
    {
        $html = $this->dispatch('/forgot-password');

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('value="' . self::CSRF . '"', $html);
    }

    // ── The one value that crosses from the route into a template ─────────────

    public function testTheResetTokenTravelsFromTheUrlIntoTheForm_NFR81(): void
    {
        $html = $this->dispatch('/reset-password/aBc123-XYZ');

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('name="token" value="aBc123-XYZ"', $html);
        $this->assertStringContainsString('value="' . self::CSRF . '"', $html);
    }

    /**
     * The token is a route segment, which means it is user input, which means
     * Law 3 applies to it on the way out however innocuous the pattern looks.
     * The router hands params over exactly as they appeared in the path, so
     * this is the value the template really receives.
     */
    public function testTheResetTokenIsEscapedOnTheWayOut_NFR81(): void
    {
        $html = $this->dispatch('/reset-password/"><b>x');

        $this->assertStringNotContainsString('"><b>x', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;b&gt;x', $html);
    }

    // ── The templates no longer do the controller's work ──────────────────────

    public function testTheConvertedTemplatesOnlyRender_NFR81(): void
    {
        $templates = [
            'home.php',
            'privacy.php',
            'auth/login.php',
            'auth/forgot.php',
            'auth/reset.php',
        ];

        foreach ($templates as $template) {
            $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/templates/' . $template);

            $this->assertStringNotContainsString('ob_start(', $source, $template . ' must not buffer itself.');
            $this->assertStringNotContainsString('ob_get_clean(', $source, $template . ' must not buffer itself.');
            $this->assertStringNotContainsString('layouts/', $source, $template . ' must not include a layout.');
            $this->assertStringNotContainsString('CsrfMiddleware', $source, $template . ' must be handed its CSRF token.');
        }
    }

    public function testThePublicControllersShareTheHtmlBoundary_NFR81(): void
    {
        $this->assertInstanceOf(HtmlController::class, new PageController());
        $this->assertInstanceOf(HtmlController::class, new AuthPageController());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function dispatch(string $uri): string
    {
        $router = new Router();
        $reflection = new ReflectionClass(Bootstrap::class);
        $reflection->getMethod('registerRoutes')->invoke(null, $router, \dirname(__DIR__, 2));

        // Seed a non-200 code so that a 200 afterwards can only come from the route.
        http_response_code(500);

        ob_start();
        $router->dispatch('GET', $uri);

        return (string) ob_get_clean();
    }
}
