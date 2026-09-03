<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Bootstrap;
use EduQR\Controllers\HtmlController;
use EduQR\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The admin HTML routes, now behind controllers (NFR-81).
 *
 * These pages cannot be dispatched from a test the way the public ones can:
 * every one of them authenticates, and `AuthMiddleware` answers a request with
 * no session by redirecting and calling `exit`, which would end the test run
 * rather than the request. So the route is checked from the outside — it is
 * registered, it names a controller, and the template it ends at no longer does
 * any of the controller's work — and the render contract itself is covered by
 * `Unit\Controllers\HtmlControllerTest` and `HtmlControllerAuthTest`.
 *
 * The variable test is the one that earns its place. A template that lost a
 * variable in the move would not fail loudly; `<?= $course['title'] ?>` on an
 * undefined `$course` is a warning and an empty string, so the page would
 * render, slightly wrong, and no assertion above would notice. It reads each
 * template's free variables — the ones it names but never assigns — and checks
 * that its controller hands over every one.
 *
 * @requirement NFR-81
 */
final class AdminHtmlRoutesTest extends TestCase
{
    /**
     * `$instructor` and `$csrfToken` are the admin layout's own chrome, which
     * `HtmlController::requireUser()` records for every authenticated page. A
     * template may name them without its controller passing them.
     */
    private const CHROME = ['instructor', 'csrfToken'];

    /**
     * route pattern => [template under templates/, controller class, method].
     *
     * @return array<string, array{string, string, class-string, string}>
     */
    public static function adminPages(): array
    {
        return [
            '/admin/courses' => [
                'admin/courses/list.php',
                \EduQR\Controllers\Admin\CourseController::class,
                'index',
            ],
            '/admin/courses/new' => [
                'admin/courses/create.php',
                \EduQR\Controllers\Admin\CourseController::class,
                'create',
            ],
            '/admin/courses/{id}' => [
                'admin/courses/detail.php',
                \EduQR\Controllers\Admin\CourseController::class,
                'show',
            ],
            '/admin/courses/{id}/analytics' => [
                'admin/courses/analytics.php',
                \EduQR\Controllers\Admin\CourseController::class,
                'analytics',
            ],
            '/admin/courses/{id}/edit' => [
                'admin/courses/edit.php',
                \EduQR\Controllers\Admin\CourseController::class,
                'edit',
            ],
        ];
    }

    // ── The route reaches a controller ────────────────────────────────────────

    #[DataProvider('adminPages')]
    public function test_an_admin_page_route_is_registered_and_names_no_template_NFR81(
        string $template,
        string $controller,
        string $method,
    ): void {
        $pattern = $this->dataName();

        $this->assertContains($pattern, $this->registeredGetPatterns(), $pattern . ' must be registered.');

        $this->assertStringNotContainsString(
            'templates/' . $template,
            $this->sourceOf(\dirname(__DIR__, 2) . '/src/Bootstrap.php'),
            $pattern . ' must reach ' . $template . ' through a controller, not through an include.',
        );

        $this->assertTrue(
            method_exists($controller, $method),
            $controller . '::' . $method . '() must exist to serve ' . $pattern . '.',
        );

        $this->assertTrue(
            is_subclass_of($controller, HtmlController::class),
            $controller . ' must render through the shared HTML boundary.',
        );
    }

    // ── The template stopped doing the controller's work ──────────────────────

    #[DataProvider('adminPages')]
    public function test_a_converted_admin_template_only_renders_NFR81(string $template): void
    {
        $source = $this->templateSource($template);

        $forbidden = [
            'ob_start(' => 'must not buffer itself',
            'ob_get_clean(' => 'must not buffer itself',
            'layouts/' => 'must not include a layout',
            'AuthMiddleware' => 'must not authenticate the request',
            'CsrfMiddleware' => 'must be handed its CSRF token',
            'Container::' => 'must not resolve a service',
            'http_response_code(' => 'must not decide a status code',
            'templates/errors/' => 'must not render an error page',
        ];

        foreach ($forbidden as $needle => $why) {
            $this->assertStringNotContainsString($needle, $source, $template . ' ' . $why . '.');
        }
    }

    // ── Every variable the template names arrives through $data ───────────────

    #[DataProvider('adminPages')]
    public function test_every_variable_a_template_names_is_handed_to_it_NFR81(
        string $template,
        string $controller,
    ): void {
        $handedOver = $this->viewDataKeysFor($controller, $template);

        foreach ($this->freeVariablesOf($this->templateSource($template)) as $name) {
            if (\in_array($name, self::CHROME, true)) {
                continue;
            }

            $this->assertContains(
                $name,
                $handedOver,
                $template . ' reads $' . $name . ', which ' . $controller . ' does not pass to it.',
            );
        }
    }

    /** A template with no free variables would make the test above vacuous. */
    public function test_the_variable_check_actually_finds_variables_NFR81(): void
    {
        $this->assertSame(
            ['course', 'csrfToken', 'isCourseOwner', 'sessions'],
            $this->freeVariablesOf($this->templateSource('admin/courses/detail.php')),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * The names a template reads without ever assigning them — what it must be
     * handed. A `foreach` value, a closure parameter and a plain assignment all
     * count as the template supplying its own, which is why this walks tokens
     * rather than matching `$name` with a regular expression.
     *
     * @return list<string>
     */
    private function freeVariablesOf(string $source): array
    {
        $tokens = token_get_all($source);
        $count = \count($tokens);

        $used = [];
        $assigned = [];
        $depth = 0;
        $parameterDepth = null;

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if ($token === '(') {
                ++$depth;

                continue;
            }

            if ($token === ')') {
                if ($parameterDepth === $depth) {
                    $parameterDepth = null;
                }
                --$depth;

                continue;
            }

            // `foreach (... as $k => $v)` binds everything between `as` and `)`.
            if (\is_array($token) && $token[0] === T_AS) {
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ')'; ++$j) {
                    if (\is_array($tokens[$j]) && $tokens[$j][0] === T_VARIABLE) {
                        $assigned[ltrim($tokens[$j][1], '$')] = true;
                    }
                }

                continue;
            }

            if (\is_array($token) && \in_array($token[0], [T_FUNCTION, T_FN], true)) {
                $parameterDepth = $depth + 1;

                continue;
            }

            if (! \is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }

            $name = ltrim($token[1], '$');
            $used[$name] = true;

            if ($parameterDepth === $depth) {
                $assigned[$name] = true;

                continue;
            }

            if ($this->nextSignificant($tokens, $i) === '=') {
                $assigned[$name] = true;
            }
        }

        foreach (array_keys($used) as $name) {
            if (str_starts_with($name, '_') || $name === 'GLOBALS' || $name === 'this') {
                unset($used[$name]);
            }
        }

        $free = array_keys(array_diff_key($used, $assigned));
        sort($free);

        return $free;
    }

    /** @return array|string|null */
    private function nextSignificant(array $tokens, int $from)
    {
        for ($j = $from + 1, $count = \count($tokens); $j < $count; ++$j) {
            if (\is_array($tokens[$j]) && \in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$j];
        }

        return null;
    }

    /**
     * The `'name' =>` keys of the `$data` array in the `render()` call for one
     * template. Read from source rather than by calling the method, because
     * calling it would authenticate and hit the database.
     *
     * @return list<string>
     */
    private function viewDataKeysFor(string $controller, string $template): array
    {
        $source = $this->sourceOf((new ReflectionClass($controller))->getFileName());

        $start = strpos($source, "'" . $template . "'");
        self::assertNotFalse($start, $controller . ' must render ' . $template . '.');

        $end = strpos($source, 'self::LAYOUT_', $start);
        self::assertNotFalse($end, $controller . ' must choose a layout for ' . $template . '.');

        preg_match_all("/'([a-zA-Z][a-zA-Z0-9_]*)'\s*=>/", substr($source, $start, $end - $start), $matches);

        return $matches[1];
    }

    private function templateSource(string $template): string
    {
        return $this->sourceOf(\dirname(__DIR__, 2) . '/templates/' . $template);
    }

    private function sourceOf(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Could not read ' . $path);

        return $source;
    }

    /** @return list<string> */
    private function registeredGetPatterns(): array
    {
        $router = new Router();
        (new ReflectionClass(Bootstrap::class))
            ->getMethod('registerRoutes')
            ->invoke(null, $router, \dirname(__DIR__, 2));

        $routes = (new ReflectionClass(Router::class))->getProperty('routes')->getValue($router);

        $patterns = [];

        foreach ($routes as $route) {
            if ($route['method'] === 'GET') {
                $patterns[] = $route['pattern'];
            }
        }

        return $patterns;
    }
}
