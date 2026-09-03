<?php

declare(strict_types=1);

namespace EduQR\Controllers;

use EduQR\Exceptions\DomainException;
use EduQR\Middleware\AuthMiddleware;
use EduQR\Middleware\CsrfMiddleware;

/**
 * The one way an HTML route reaches a template (NFR-81).
 *
 * {@see ApiController} is this class's sibling: it owns the JSON boundary, this
 * one owns the HTML boundary. Before this class, every HTML route was a router
 * closure that set `Content-Type` by hand and included a template that then
 * authenticated the request, resolved services, queried repositories and closed
 * with its own copy of the same nine lines of `ob_start()` / `ob_get_clean()` /
 * `include` of a layout. NFR-81 moves all of that above the template: a
 * controller prepares the data and calls {@see self::render()}; a template
 * receives that data and renders it.
 *
 * ## What a template may assume
 *
 * Exactly two things, and nothing else:
 *
 * 1. Every key of the `$data` array handed to `render()` is in scope as an
 *    ordinary variable of that name — `['course' => $c]` arrives as `$course`.
 * 2. Its own output is captured; it must not open a buffer or include a layout.
 *
 * A template is included from a **static** method, so `$this` is unbound inside
 * it. That is deliberate: a template cannot call back into its controller.
 *
 * ## Why `extract()` and not a `$data` array the template indexes
 *
 * `$data['course']['title']` reads worse than `$course['title']`, and the
 * templates this contract has to absorb are large — `admin/sessions/detail.php`
 * is 1243 lines and names dozens of view variables. Rewriting every reference
 * in those files would be a far bigger diff than the relocation this task is,
 * and every rewritten reference is a chance to change a rendered byte. Named
 * variables keep the template bodies untouched.
 *
 * The standing objection to `extract()` is that it lets untrusted input invent
 * variables. That objection is about `extract($_POST)`, which AGENTS.md §10
 * forbids and this class never does: the array is written by a controller, its
 * keys are literals in controller source, and `EXTR_SKIP` means it can never
 * overwrite a variable the renderer already holds — including the path it is
 * about to include.
 *
 * ## The layout's own variables
 *
 * `layouts/admin.php` renders more than `$content` and `$pageTitle`: its navbar
 * shows the signed-in user's name, hides the audit-log link from non-admins,
 * and carries a logout form with a CSRF field. Those two variables used to
 * reach it by scope leakage — the template authenticated, assigned
 * `$instructor` and `$csrfToken`, and then included the layout from its own
 * scope. Once the layout is included by this class instead, that leak is gone,
 * and without a replacement every admin page would silently lose its navbar
 * chrome. {@see self::requireUser()} is that replacement: it records the two
 * variables when it authenticates, and {@see self::render()} passes them to the
 * frame. A page that never authenticates has no chrome and passes none, which
 * is exactly what the public and projector layouts want.
 *
 * ## Error pages
 *
 * `templates/errors/` holds pages for 403, 404 and 500 only. For any other
 * status {@see self::renderError()} sends the **real** status and renders the
 * generic 500 page: the status line is what a monitor, a cache and a test read,
 * so falsifying it to match the available artwork would be the worse trade. A
 * route that wants a better answer than the generic page — a 422 on a form POST,
 * say — should re-render its own template with the validation message rather
 * than reach for an error page at all.
 *
 * @requirement NFR-81
 */
abstract class HtmlController
{
    public const LAYOUT_ADMIN = 'admin';
    public const LAYOUT_PROJECTOR = 'projector';
    public const LAYOUT_PUBLIC = 'public';

    /** The closed set of frames under `templates/layouts/`. */
    private const LAYOUTS = [self::LAYOUT_ADMIN, self::LAYOUT_PROJECTOR, self::LAYOUT_PUBLIC];

    /** The statuses that have a page of their own under `templates/errors/`. */
    private const ERROR_PAGES = [403, 404, 500];

    /**
     * What the layout needs beyond `$content` and `$pageTitle`, filled in by
     * {@see self::requireUser()}. Empty until the request is authenticated.
     *
     * @var array<string, mixed>
     */
    private array $layoutChrome = [];

    // ── Authentication ────────────────────────────────────────────────────────

    /**
     * Authenticate the request and return the current user.
     *
     * Eleven admin templates opened with `AuthMiddleware::require()`. This is
     * where that line went, once, rather than into eleven controllers.
     *
     * **It returns the user; it does not throw.** Turning a missing session into
     * an `AuthenticationException` for {@see self::renderDomainError()} to catch
     * was the alternative, and it would have changed what the eleven routes
     * answer. `AuthMiddleware` does not merely report the failure — it answers
     * it, and the answer is not an error page: an HTML caller with no session is
     * sent `302 Location: /login`, and one whose role is wrong gets the 403 page
     * directly. A 401 error page in place of that redirect would be a worse
     * page and a broken sign-in flow. The typed exceptions describe failures a
     * *service* reports back to its caller; this failure is already resolved by
     * the time control could reach a `catch`.
     *
     * The content type goes out first because that is where the router closures
     * being replaced sent it — before the template ran, therefore before the
     * template authenticated. Both of `AuthMiddleware`'s HTML answers were
     * emitted under that header and neither sets one of its own, so sending it
     * here keeps those two responses byte-for-byte what they were.
     *
     * @param string ...$roles roles allowed through; none means any signed-in user
     *
     * @return array<string, mixed> id, email, role, display_name
     */
    protected function requireUser(string ...$roles): array
    {
        header('Content-Type: text/html; charset=utf-8');

        $user = $this->authenticateRequest(...$roles);

        $this->layoutChrome = [
            'instructor' => $user,
            'csrfToken' => CsrfMiddleware::getToken(),
        ];

        return $user;
    }

    /**
     * The middleware call by itself.
     *
     * Separated for the same reason {@see self::templateRoot()} is overridable:
     * so that the contract above it can be exercised without the real thing
     * underneath — here, rendering an authenticated page without starting a PHP
     * session. Nothing under `src/` overrides it.
     *
     * @return array<string, mixed>
     */
    protected function authenticateRequest(string ...$roles): array
    {
        return $roles === []
            ? AuthMiddleware::require()
            : AuthMiddleware::requireRole(...$roles);
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    /**
     * Render a template inside a layout and send it.
     *
     * Headers go out before the template runs, matching the order the router
     * closures this replaces used: the closure set the status and the
     * content type, then included the template. Nothing has actually been
     * written at that point — the body is buffered — so a template that fails
     * can still be answered with {@see self::renderError()}.
     *
     * `$pageTitle` is passed through as given, `null` included: the layouts
     * already default it to `t('app.name')` and keeping that default in one
     * place means a caller with no title of its own does not have to know it.
     *
     * @param string               $template  path under `templates/`, e.g. `auth/login.php`
     * @param array<string, mixed> $data      view variables, by name
     * @param string               $layout    one of the `LAYOUT_*` constants
     */
    protected function render(
        string $template,
        array $data = [],
        ?string $pageTitle = null,
        string $layout = self::LAYOUT_PUBLIC,
        int $status = 200
    ): void {
        if (! \in_array($layout, self::LAYOUTS, true)) {
            throw new \InvalidArgumentException('Unknown layout: ' . $layout);
        }

        $body = $this->templatePath($template);
        $frame = $this->templatePath('layouts/' . $layout . '.php');

        $this->sendHtmlHeaders($status);

        // The union is written this way round so that the chrome can only add to
        // the frame's scope, never redefine the two variables it exists to frame.
        self::emit($frame, [
            'content' => self::capture($body, $data),
            'pageTitle' => $pageTitle,
        ] + $this->layoutChrome);
    }

    // ── Error pages ───────────────────────────────────────────────────────────

    /**
     * Send `$status` and render the error page for it.
     *
     * The page falls back to 500 when the status has none; the status itself
     * never falls back. See the class docblock for why.
     */
    protected function renderError(int $status): void
    {
        $page = \in_array($status, self::ERROR_PAGES, true) ? $status : 500;

        $this->sendHtmlHeaders($status);

        self::emit($this->templatePath('errors/' . $page . '.php'), []);
    }

    /**
     * Answer a domain failure with the error page its status asks for.
     *
     * The status is read off the exception rather than decided here, for the
     * reason {@see ApiController} documents at length: a status is a property of
     * the throw site, not of the error code (SYSTEM_ARCHITECTURE.md §9.1).
     */
    protected function renderDomainError(DomainException $e): void
    {
        $this->renderError($e->getStatus());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The `<lead> — eduQR` title almost every page uses.
     *
     * The separator is punctuation rather than a phrase, so it is not a Law 1
     * string; both halves around it are translated.
     */
    protected static function titleWithAppName(string $lead): string
    {
        return $lead . ' — ' . t('app.name');
    }

    protected function sendHtmlHeaders(int $status): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
    }

    /**
     * Where templates live. Overridable so the render contract can be tested
     * against a throwaway template tree instead of the real one.
     */
    protected function templateRoot(): string
    {
        return \dirname(__DIR__, 2) . '/templates';
    }

    /**
     * `..` is rejected rather than resolved. Template names are literals in
     * controller source today, but a name assembled from data — a per-type
     * partial such as `student/question/<type>.php` — is the obvious next step,
     * and this is where that would go wrong.
     */
    private function templatePath(string $relative): string
    {
        if (str_contains($relative, '..')) {
            throw new \InvalidArgumentException('Template path may not traverse: ' . $relative);
        }

        return $this->templateRoot() . '/' . ltrim($relative, '/');
    }

    /**
     * Render a template to a string.
     *
     * A template that throws half-way through has already written into the
     * buffer. Discarding it is what makes {@see self::renderError()} usable from
     * a `catch` around `render()` — otherwise the error page would be served
     * with half a real page glued to the front of it.
     *
     * @param array<string, mixed> $__eduqrData
     */
    private static function capture(string $__eduqrTemplate, array $__eduqrData): string
    {
        ob_start();

        try {
            self::emit($__eduqrTemplate, $__eduqrData);
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }

    /**
     * Include a template with `$__eduqrData` unpacked into its scope.
     *
     * The two locals are named for this method rather than for the view so that
     * no plausible view variable collides with them, and `EXTR_SKIP` makes the
     * collision harmless if one ever does.
     *
     * @param array<string, mixed> $__eduqrData
     */
    private static function emit(string $__eduqrTemplate, array $__eduqrData): void
    {
        extract($__eduqrData, EXTR_SKIP);

        include $__eduqrTemplate;
    }
}
