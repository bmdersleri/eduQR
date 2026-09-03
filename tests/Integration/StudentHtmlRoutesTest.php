<?php

declare(strict_types=1);

namespace EduQR\Tests\Integration;

use EduQR\Bootstrap;
use EduQR\Controllers\HtmlController;
use EduQR\Controllers\Public\ProjectorController;
use EduQR\Controllers\Public\StudentController;
use EduQR\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The student and projector HTML routes, now behind controllers (NFR-81).
 *
 * `AdminHtmlRoutesTest` is this class's sibling and these assertions are its
 * assertions; the two files differ only in the route set they are pointed at.
 * Its helpers are copied rather than lifted into a shared base class because
 * they are small, and a base class shared by two files that assert about
 * disjoint route sets buys nothing but a place to look things up.
 *
 * These routes cannot be dispatched from a test either, though for a different
 * reason than the admin ones: **there is no test database**. Every one of them
 * queries a repository on its first statement, so dispatching one would try to
 * open a connection that does not exist. The route is therefore checked from
 * the outside — it is registered, it names a controller, and the template it
 * ends at no longer does any of the controller's work — and the render contract
 * itself is covered by `Unit\Controllers\HtmlControllerTest`.
 *
 * The variable test is the one that earns its place. A template that lost a
 * variable in the move would not fail loudly; `<?= $session['title'] ?>` on an
 * undefined `$session` is a warning and an empty string, so the page would
 * render, slightly wrong, and no assertion above would notice.
 *
 * @requirement NFR-81
 */
final class StudentHtmlRoutesTest extends TestCase
{
    /**
     * The eight routes, as [verb, pattern, template, controller, method].
     *
     * `POST /play/{short_code}` is the no-JS answer fallback (T-710). It shares
     * both its template and its controller method with the GET: the six gates
     * in front of the POST handler are identical, so the two verbs are one
     * method that branches on `REQUEST_METHOD`.
     *
     * @return array<string, array{string, string, string, class-string, string}>
     */
    public static function publicPages(): array
    {
        return [
            'GET /live/{short_code}' => [
                'GET',
                '/live/{short_code}',
                'live/session.php',
                ProjectorController::class,
                'session',
            ],
            'GET /live/{short_code}/results' => [
                'GET',
                '/live/{short_code}/results',
                'live/results.php',
                ProjectorController::class,
                'results',
            ],
            'GET /join/{short_code}/wait' => [
                'GET',
                '/join/{short_code}/wait',
                'student/wait.php',
                StudentController::class,
                'wait',
            ],
            'GET /join/{short_code}' => [
                'GET',
                '/join/{short_code}',
                'student/join.php',
                StudentController::class,
                'join',
            ],
            'GET /play/{short_code}/answered' => [
                'GET',
                '/play/{short_code}/answered',
                'student/answered.php',
                StudentController::class,
                'answered',
            ],
            'GET /play/{short_code}/batch' => [
                'GET',
                '/play/{short_code}/batch',
                'student/batch.php',
                StudentController::class,
                'batch',
            ],
            'GET /play/{short_code}' => [
                'GET',
                '/play/{short_code}',
                'student/play.php',
                StudentController::class,
                'play',
            ],
            'POST /play/{short_code}' => [
                'POST',
                '/play/{short_code}',
                'student/play.php',
                StudentController::class,
                'play',
            ],
        ];
    }

    /**
     * Every template these controllers render, the four hoisted ones included.
     *
     * `student/join/closed.php` and its three siblings were bodies the join and
     * play templates wrapped in a layout mid-file and `exit`ed after. They are
     * reached only from a controller, never from a route, so they have no row
     * in the provider above — but they are templates, and the rules a template
     * lives under apply to them just the same.
     *
     * @return array<string, array{string}>
     */
    public static function publicTemplates(): array
    {
        $templates = [
            'live/session.php',
            'live/results.php',
            'student/wait.php',
            'student/answered.php',
            'student/batch.php',
            'student/join.php',
            'student/join/closed.php',
            'student/join/paused.php',
            'student/play.php',
            'student/play/closed.php',
            'student/play/paused.php',
        ];

        return array_combine(
            $templates,
            array_map(static fn (string $template): array => [$template], $templates),
        );
    }

    // ── The route reaches a controller ────────────────────────────────────────

    #[DataProvider('publicPages')]
    public function test_a_public_page_route_is_registered_and_names_no_template_NFR81(
        string $verb,
        string $pattern,
        string $template,
        string $controller,
        string $method,
    ): void {
        $this->assertContains(
            $pattern,
            $this->registeredPatterns($verb),
            $verb . ' ' . $pattern . ' must be registered.',
        );

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

    /**
     * The no-JS fallback posts to the page it was served from, and both verbs
     * have to end at the same method — otherwise the six gates in front of the
     * POST handler would have to be duplicated, which is the thing that made
     * splitting them a bad idea in the first place.
     */
    public function test_both_verbs_of_the_play_route_reach_one_method_NFR81(): void
    {
        $this->assertContains('/play/{short_code}', $this->registeredPatterns('POST'));

        $this->assertSame(
            2,
            substr_count(
                $this->sourceOf(\dirname(__DIR__, 2) . '/src/Bootstrap.php'),
                "(new Controllers\\Public\\StudentController())->play(\$p['short_code'])",
            ),
            'GET and POST /play/{short_code} must both reach StudentController::play().',
        );
    }

    /**
     * The router matches first-registered-first, so two of these routes only
     * ever match because they are registered before the pattern that would
     * otherwise swallow them.
     */
    public function test_the_more_specific_student_routes_are_registered_first_NFR81(): void
    {
        $patterns = $this->registeredPatterns('GET');

        $this->assertLessThan(
            array_search('/join/{short_code}', $patterns, true),
            array_search('/join/{short_code}/wait', $patterns, true),
            '/join/{short_code}/wait must be registered before /join/{short_code}.',
        );

        $this->assertLessThan(
            array_search('/play/{short_code}', $patterns, true),
            array_search('/play/{short_code}/answered', $patterns, true),
            '/play/{short_code}/answered must be registered before /play/{short_code}.',
        );
    }

    // ── The template stopped doing the controller's work ──────────────────────

    /**
     * The admin needle list, minus the two middleware names — none of these
     * pages authenticate — and plus the request superglobals and `setcookie()`.
     * These templates read cookies, query strings and the request method to
     * decide what to draw and where to send the visitor. All of that is request
     * input, and reading request input is what a controller is for.
     */
    #[DataProvider('publicTemplates')]
    public function test_a_converted_public_template_only_renders_NFR81(string $template): void
    {
        $source = $this->templateSource($template);

        $forbidden = [
            'ob_start(' => 'must not buffer itself',
            'ob_get_clean(' => 'must not buffer itself',
            'layouts/' => 'must not include a layout',
            'Container::' => 'must not resolve a service',
            'http_response_code(' => 'must not decide a status code',
            'templates/errors/' => 'must not render an error page',
            '$_COOKIE' => 'must be handed what the cookie said',
            '$_GET' => 'must be handed the query it needs',
            '$_POST' => 'must not handle a form post',
            '$_SERVER' => 'must not inspect the request',
            'setcookie(' => 'must not write a cookie',
        ];

        foreach ($forbidden as $needle => $why) {
            $this->assertStringNotContainsString($needle, $source, $template . ' ' . $why . '.');
        }
    }

    /**
     * A template names no class.
     *
     * `admin/courses/edit.php` compared each instructor row against
     * `CourseService::ROLE_OWNER` without importing it. A template runs in the
     * global namespace, so that resolved to `\CourseService`, and the page
     * fatalled the moment a course had a co-instructor to list. Neither the
     * needle list above nor the variable check below can see that; this can.
     */
    #[DataProvider('publicTemplates')]
    public function test_a_converted_public_template_names_no_class_NFR81(string $template): void
    {
        $this->assertSame(
            [],
            $this->staticReferencesIn($this->templateSource($template)),
            $template . ' must be handed the answer rather than ask a class for it.',
        );
    }

    /**
     * The guard above is only worth having if it can see a reference, so it is
     * pointed at the one these templates used to make.
     */
    public function test_the_class_check_actually_finds_a_class_NFR81(): void
    {
        $this->assertSame(
            ['Container::sessionRepository'],
            $this->staticReferencesIn('<?php echo Container::sessionRepository(); ?>'),
        );
    }

    // ── Every variable the template names arrives through $data ───────────────

    #[DataProvider('publicPages')]
    public function test_every_variable_a_template_names_is_handed_to_it_NFR81(
        string $verb,
        string $pattern,
        string $template,
        string $controller,
    ): void {
        $handedOver = $this->viewDataKeysFor($controller, $template);

        foreach ($this->freeVariablesOf($this->templateSource($template)) as $name) {
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
            ['noJsError', 'optionCount', 'options', 'qText', 'qType', 'question', 'session', 'shortCode'],
            $this->freeVariablesOf($this->templateSource('student/play.php')),
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Every `Class::` a template writes, in source order.
     *
     * Token-based rather than a regular expression so that a class name inside
     * a comment or a string is not mistaken for a reference the parser has to
     * resolve — only the ones that would actually fatal count.
     *
     * @return list<string>
     */
    private function staticReferencesIn(string $source): array
    {
        $tokens = token_get_all($source);
        $references = [];

        foreach ($tokens as $i => $token) {
            if ($token !== '::' && (! \is_array($token) || $token[0] !== T_DOUBLE_COLON)) {
                continue;
            }

            $class = $this->previousSignificant($tokens, $i);
            $member = $this->nextSignificant($tokens, $i);

            $references[] = (\is_array($class) ? $class[1] : (string) $class)
                . '::' . (\is_array($member) ? $member[1] : (string) $member);
        }

        return $references;
    }

    /** @return array|string|null */
    private function previousSignificant(array $tokens, int $from)
    {
        for ($j = $from - 1; $j >= 0; --$j) {
            if (\is_array($tokens[$j]) && \in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $tokens[$j];
        }

        return null;
    }

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
     * calling it would query a database this test suite does not have.
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

    /**
     * The patterns registered for one verb, in registration order.
     *
     * `AdminHtmlRoutesTest` has the same helper hard-wired to GET; this one
     * takes the verb because the no-JS answer fallback is a POST, and because
     * the order the list comes back in is itself load-bearing.
     *
     * @return list<string>
     */
    private function registeredPatterns(string $method): array
    {
        $router = new Router();
        (new ReflectionClass(Bootstrap::class))
            ->getMethod('registerRoutes')
            ->invoke(null, $router, \dirname(__DIR__, 2));

        $routes = (new ReflectionClass(Router::class))->getProperty('routes')->getValue($router);

        $patterns = [];

        foreach ($routes as $route) {
            if ($route['method'] === $method) {
                $patterns[] = $route['pattern'];
            }
        }

        return $patterns;
    }
}
