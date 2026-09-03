<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Controllers;

use EduQR\Controllers\HtmlController;
use EduQR\Exceptions\DomainException;
use EduQR\Exceptions\ForbiddenException;
use EduQR\Exceptions\NotFoundException;
use EduQR\Exceptions\ValidationException;
use EduQR\I18n\I18nService;
use PHPUnit\Framework\TestCase;

/**
 * The shared HTML boundary (NFR-81).
 *
 * The render-contract tests run against a throwaway template tree rather than
 * against `templates/`, because what is under test is the contract — which
 * variables a template sees, who buffers, who wraps — and a real page would
 * bury that under a hundred lines of markup. The error-page tests do the
 * opposite and use the real `templates/errors/`, because there the artwork is
 * the subject: which of the three files a status lands on.
 *
 * @requirement NFR-81
 */
final class HtmlControllerTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        I18nService::init(\dirname(__DIR__, 3) . '/locales', 'en');

        $this->root = sys_get_temp_dir() . '/eduqr-html-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/layouts', 0o777, true);

        // Echoes what it can see, so a test can assert on scope rather than markup.
        file_put_contents(
            $this->root . '/probe.php',
            '<?php echo \'name=\' . ($name ?? \'ABSENT\') . \';n=\' . ($n ?? \'ABSENT\') . \';\';',
        );

        // Writes, then fails: the half-written page must not survive.
        file_put_contents(
            $this->root . '/boom.php',
            '<?php echo \'HALF-A-PAGE\'; throw new \RuntimeException(\'boom\');',
        );

        file_put_contents(
            $this->root . '/layouts/public.php',
            '<?php echo \'PUBLIC(\' . ($pageTitle ?? \'UNTITLED\') . \'){\' . ($content ?? \'\') . \'}\';',
        );

        file_put_contents(
            $this->root . '/layouts/admin.php',
            '<?php echo \'ADMIN{\' . ($content ?? \'\') . \'}\';',
        );

        http_response_code(200);
    }

    protected function tearDown(): void
    {
        foreach (['probe.php', 'boom.php', 'layouts/public.php', 'layouts/admin.php'] as $file) {
            @unlink($this->root . '/' . $file);
        }
        @rmdir($this->root . '/layouts');
        @rmdir($this->root);
    }

    // ── What a template may assume is in scope ────────────────────────────────

    public function test_view_data_arrives_as_named_variables_NFR81(): void
    {
        $html = $this->capture(fn (RenderProbe $c) => $c->show('probe.php', ['name' => 'Ada', 'n' => 7]));

        $this->assertStringContainsString('name=Ada;n=7;', $html);
    }

    public function test_a_variable_the_controller_did_not_pass_is_simply_absent_NFR81(): void
    {
        $html = $this->capture(fn (RenderProbe $c) => $c->show('probe.php', ['name' => 'Ada']));

        $this->assertStringContainsString('name=Ada;n=ABSENT;', $html);
    }

    /**
     * The one hazard of unpacking an array into scope: a key that shadows a
     * variable the renderer is itself standing on. `EXTR_SKIP` makes the attempt
     * a no-op rather than a redirected `include`.
     */
    public function test_view_data_cannot_overwrite_the_renderers_own_variables_NFR81(): void
    {
        $html = $this->capture(fn (RenderProbe $c) => $c->show('probe.php', [
            '__eduqrTemplate' => $this->root . '/boom.php',
            '__eduqrData' => 'nonsense',
            'name' => 'Ada',
        ]));

        $this->assertStringContainsString('name=Ada;', $html);
        $this->assertStringNotContainsString('HALF-A-PAGE', $html);
    }

    // ── The layout ────────────────────────────────────────────────────────────

    public function test_the_layout_wraps_the_captured_body_NFR81(): void
    {
        $html = $this->capture(fn (RenderProbe $c) => $c->show('probe.php', ['name' => 'Ada'], 'A Title'));

        $this->assertSame('PUBLIC(A Title){name=Ada;n=ABSENT;}', $html);
    }

    public function test_the_layout_is_chosen_by_name_NFR81(): void
    {
        $html = $this->capture(
            fn (RenderProbe $c) => $c->show('probe.php', [], null, HtmlController::LAYOUT_ADMIN),
        );

        $this->assertSame('ADMIN{name=ABSENT;n=ABSENT;}', $html);
    }

    /**
     * A caller with no title of its own passes none; the layout's own default
     * stays the single place that knows what an untitled page is called.
     */
    public function test_an_absent_page_title_is_left_to_the_layout_NFR81(): void
    {
        $html = $this->capture(fn (RenderProbe $c) => $c->show('probe.php'));

        $this->assertStringContainsString('PUBLIC(UNTITLED)', $html);
    }

    public function test_an_unknown_layout_is_rejected_NFR81(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->capture(fn (RenderProbe $c) => $c->show('probe.php', [], null, 'wallpaper'));
    }

    public function test_a_traversing_template_path_is_rejected_NFR81(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->capture(fn (RenderProbe $c) => $c->show('../composer.json'));
    }

    // ── Status and headers ────────────────────────────────────────────────────

    public function test_a_rendered_page_answers_200_NFR81(): void
    {
        http_response_code(500);

        $this->capture(fn (RenderProbe $c) => $c->show('probe.php'));

        $this->assertSame(200, http_response_code());
    }

    public function test_a_render_may_carry_another_status_NFR81(): void
    {
        $this->capture(
            fn (RenderProbe $c) => $c->show('probe.php', [], null, HtmlController::LAYOUT_PUBLIC, 422),
        );

        $this->assertSame(422, http_response_code());
    }

    // ── A template that fails mid-render ──────────────────────────────────────

    /**
     * Without this, an error page rendered from a `catch` around `render()`
     * would be served with the failed page's first half glued to its front.
     */
    public function test_a_failing_template_leaves_no_output_behind_NFR81(): void
    {
        $depth = ob_get_level();

        ob_start();

        try {
            (new RenderProbe($this->root))->show('boom.php');
            $this->fail('The template exception should have propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $html = (string) ob_get_clean();

        $this->assertSame('', $html);
        $this->assertSame($depth, ob_get_level());
    }

    // ── Error pages, against the real templates/errors/ ───────────────────────

    public function test_each_error_status_renders_its_own_page_NFR81(): void
    {
        $this->assertStringContainsString(t('error.forbidden'), $this->captureError(403));
        $this->assertStringContainsString(t('error.not_found'), $this->captureError(404));
        $this->assertStringContainsString(t('error.server_error'), $this->captureError(500));
    }

    public function test_an_error_page_answers_with_its_status_NFR81(): void
    {
        $this->captureError(404);

        $this->assertSame(404, http_response_code());
    }

    /**
     * Only 403, 404 and 500 have artwork. A 422 keeps its status — that is what
     * a monitor and a test read — and borrows the generic page.
     */
    public function test_a_status_without_a_page_keeps_its_status_and_borrows_the_generic_one_NFR81(): void
    {
        $html = $this->captureError(422);

        $this->assertSame(422, http_response_code());
        $this->assertStringContainsString(t('error.server_error'), $html);
    }

    public function test_a_domain_failure_is_answered_with_the_status_it_carries_NFR81(): void
    {
        $this->assertSame(404, $this->captureDomainStatus(new NotFoundException('course_not_found')));
        $this->assertSame(403, $this->captureDomainStatus(new ForbiddenException('course_owner_only')));

        // The status is read off the exception, not off the code: `session_closed`
        // is 410 at the door and 422 inside (SYSTEM_ARCHITECTURE.md §9.1).
        $this->assertSame(410, $this->captureDomainStatus(new ValidationException('session_closed', 410)));
    }

    // ── Title helper ──────────────────────────────────────────────────────────

    public function test_the_title_helper_appends_the_app_name_NFR81(): void
    {
        $this->assertSame(
            t('privacy.page.title') . ' — ' . t('app.name'),
            RenderProbe::title(t('privacy.page.title')),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function capture(callable $render): string
    {
        $controller = new RenderProbe($this->root);

        ob_start();

        try {
            $render($controller);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** Error pages come from the real tree, so this double carries no root. */
    private function captureError(int $status): string
    {
        ob_start();
        (new RenderProbe())->fail($status);

        return (string) ob_get_clean();
    }

    private function captureDomainStatus(DomainException $e): int
    {
        ob_start();
        (new RenderProbe())->failFrom($e);
        ob_get_clean();

        return (int) http_response_code();
    }
}

/**
 * Exposes the protected surface under test, and lets the render-contract tests
 * point at a throwaway template tree.
 */
final class RenderProbe extends HtmlController
{
    public function __construct(private readonly ?string $root = null)
    {
    }

    /** @param array<string, mixed> $data */
    public function show(
        string $template,
        array $data = [],
        ?string $pageTitle = null,
        string $layout = self::LAYOUT_PUBLIC,
        int $status = 200
    ): void {
        $this->render($template, $data, $pageTitle, $layout, $status);
    }

    public function fail(int $status): void
    {
        $this->renderError($status);
    }

    public function failFrom(DomainException $e): void
    {
        $this->renderDomainError($e);
    }

    public static function title(string $lead): string
    {
        return self::titleWithAppName($lead);
    }

    protected function templateRoot(): string
    {
        return $this->root ?? parent::templateRoot();
    }
}
