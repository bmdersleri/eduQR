<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Controllers;

use EduQR\Controllers\HtmlController;
use EduQR\I18n\I18nService;
use PHPUnit\Framework\TestCase;

/**
 * The authenticated half of the HTML boundary (NFR-81).
 *
 * `HtmlControllerTest` covers a render that nobody signed in for. This one
 * covers the other case, and the thing that makes it different: the admin
 * layout draws a navbar for the signed-in user, and before NFR-81 it read that
 * user out of the template's scope. These tests pin the replacement — that
 * `requireUser()` records the user and the CSRF token, that `render()` hands
 * them to the frame, and that a page which never authenticates still hands the
 * frame nothing.
 *
 * The real `templates/layouts/admin.php` is the frame under test, because the
 * whole point is what that file can still see.
 *
 * @requirement NFR-81
 */
final class HtmlControllerAuthTest extends TestCase
{
    private const CSRF = 'fixed-csrf-token-for-this-test';

    /** @var array<string, mixed> */
    private const USER = [
        'id' => 42,
        'email' => 'ada@example.org',
        'role' => 'instructor',
        'display_name' => 'Ada Lovelace',
    ];

    protected function setUp(): void
    {
        I18nService::init(\dirname(__DIR__, 3) . '/locales', 'en');

        $_COOKIE['csrf_token'] = self::CSRF;
        $_SERVER['REQUEST_URI'] = '/admin/dashboard';
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['csrf_token'], $_SERVER['REQUEST_URI']);
    }

    // ── What requireUser() gives the controller ───────────────────────────────

    public function test_require_user_returns_the_current_user_NFR81(): void
    {
        $probe = new AuthProbe(self::USER);

        $this->assertSame(self::USER, $probe->auth());
    }

    /**
     * The middleware answers a wrong-role caller itself and never returns, so
     * all the base class has to do is pass the roles along untouched.
     */
    public function test_the_requested_roles_reach_the_middleware_NFR81(): void
    {
        $probe = new AuthProbe(self::USER);
        $probe->auth('admin');

        $this->assertSame(['admin'], $probe->rolesAsked);
    }

    public function test_no_roles_asked_means_any_signed_in_user_NFR81(): void
    {
        $probe = new AuthProbe(self::USER);
        $probe->auth();

        $this->assertSame([], $probe->rolesAsked);
    }

    // ── What the admin layout can still see ───────────────────────────────────

    public function test_the_admin_navbar_still_names_the_signed_in_user_NFR81(): void
    {
        $html = $this->renderAdminPage(self::USER);

        $this->assertStringContainsString('Ada Lovelace', $html);
        $this->assertStringContainsString(t('nav.logout'), $html);
    }

    public function test_the_admin_logout_form_still_carries_the_csrf_token_NFR81(): void
    {
        $html = $this->renderAdminPage(self::USER);

        $this->assertStringContainsString('name="_csrf" value="' . self::CSRF . '"', $html);
    }

    /**
     * The audit-log link is admin-only, and the layout decides that from the
     * role on the user it was handed. If the chrome stopped arriving this is
     * the assertion that would notice, because the link would vanish for
     * everyone rather than merely lose its label.
     */
    public function test_the_audit_log_link_is_still_role_gated_NFR81(): void
    {
        $this->assertStringNotContainsString(
            t('nav.audit_logs'),
            $this->renderAdminPage(self::USER),
        );

        $this->assertStringContainsString(
            t('nav.audit_logs'),
            $this->renderAdminPage(['id' => 1, 'role' => 'admin', 'display_name' => 'Root']),
        );
    }

    // ── A page nobody signed in for ───────────────────────────────────────────

    /**
     * The public and projector layouts name neither variable, and a page that
     * never authenticates has no user to name. The frame must therefore get the
     * two variables it frames and nothing else.
     */
    public function test_an_unauthenticated_render_passes_no_chrome_NFR81(): void
    {
        $probe = new AuthProbe(self::USER);

        ob_start();
        $probe->showAdmin();
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('Ada Lovelace', $html);
        $this->assertStringNotContainsString(self::CSRF, $html);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $user */
    private function renderAdminPage(array $user): string
    {
        $probe = new AuthProbe($user);
        $probe->auth();

        ob_start();
        $probe->showAdmin();

        return (string) ob_get_clean();
    }
}

/**
 * Stands in for an admin controller: authenticates, then renders an admin page.
 *
 * `authenticateRequest()` is overridden so the test never needs a PHP session —
 * the real one calls `AuthMiddleware`, which for an unauthenticated HTML caller
 * redirects and exits, and an `exit` inside a test run ends the test run.
 */
final class AuthProbe extends HtmlController
{
    /** @var list<string> */
    public array $rolesAsked = [];

    /** @param array<string, mixed> $user */
    public function __construct(private readonly array $user)
    {
    }

    /** @return array<string, mixed> */
    public function auth(string ...$roles): array
    {
        return $this->requireUser(...$roles);
    }

    /** The body is empty on purpose: the frame is the subject. */
    public function showAdmin(): void
    {
        $this->render('partials/theme-toggle.php', [], 'A Title', self::LAYOUT_ADMIN);
    }

    /** @return array<string, mixed> */
    protected function authenticateRequest(string ...$roles): array
    {
        $this->rolesAsked = $roles;

        return $this->user;
    }
}
