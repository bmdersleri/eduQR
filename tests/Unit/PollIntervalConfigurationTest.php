<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Config;
use EduQR\Controllers\Admin\SessionController;
use EduQR\Controllers\Public\ProjectorController;
use EduQR\Controllers\Public\StudentController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Poll intervals come from configuration, not from a template — NFR-76.
 *
 * Two halves. The first reads `templates/` as text and fails on any
 * `setInterval()` whose delay is a numeric literal; text is the right tool
 * because the timers live in inline JavaScript, which no PHP tokenizer and no
 * reflection can see. The second proves the four keys of API_SPEC.md §1.10 are
 * actually read: each controller's interval method is invoked, so the assertion
 * is about what the screen would be handed rather than about what `Config`
 * would answer if someone asked it.
 *
 * The defaults are the numbers the templates hardcoded before this change. A
 * deployment that sets nothing polls exactly as it did.
 *
 * @requirement NFR-76
 */
final class PollIntervalConfigurationTest extends TestCase
{
    /**
     * The four keys, with the controller method that reads each one and the
     * default it must fall back to.
     *
     * @return array<string, array{0:class-string, 1:string, 2:string, 3:int}>
     */
    public static function intervalKeys(): array
    {
        return [
            'instructor session detail' => [
                SessionController::class,
                'sessionPollIntervalMs',
                'POLL_INTERVAL_INSTRUCTOR_SESSION_MS',
                5000,
            ],
            'instructor live results' => [
                SessionController::class,
                'resultsPollIntervalMs',
                'POLL_INTERVAL_INSTRUCTOR_MS',
                2000,
            ],
            'student wait and answered' => [
                StudentController::class,
                'pollIntervalMs',
                'POLL_INTERVAL_STUDENT_MS',
                3000,
            ],
            'projector results' => [
                ProjectorController::class,
                'pollIntervalMs',
                'POLL_INTERVAL_PROJECTOR_MS',
                3000,
            ],
        ];
    }

    /**
     * The template that each interval reaches, and the timer it drives.
     *
     * @return array<string, array{0:string, 1:string}>
     */
    public static function timers(): array
    {
        return [
            'instructor session detail' => [
                'admin/sessions/detail.php',
                'setInterval(refreshLive, POLL_INTERVAL_MS);',
            ],
            'instructor live results' => [
                'admin/sessions/results.php',
                'setInterval(fetchResults, POLL_INTERVAL_MS);',
            ],
            'projector results' => [
                'live/results.php',
                'setInterval(pollResults, POLL_INTERVAL_MS);',
            ],
            'student wait' => [
                'student/wait.php',
                'setInterval(pollActiveQuestion, POLL_INTERVAL_MS);',
            ],
            'student answered' => [
                'student/answered.php',
                'setInterval(pollForNextQuestion, POLL_INTERVAL_MS);',
            ],
        ];
    }

    // ── The keys are read ─────────────────────────────────────────────────────

    /**
     * @param class-string $controller
     *
     * @requirement NFR-76
     */
    #[DataProvider('intervalKeys')]
    public function testTheConfiguredIntervalIsWhatTheControllerHandsTheTemplate(
        string $controller,
        string $method,
        string $key,
        int $default,
    ): void {
        self::assertNotSame($default, 1234, 'The probe value must differ from the default.');

        $this->withConfig($key, '1234', function () use ($controller, $method): void {
            self::assertSame(1234, $this->intervalFrom($controller, $method));
        });
    }

    /**
     * A deployment that configures nothing keeps today's rate.
     *
     * @param class-string $controller
     *
     * @requirement NFR-76
     */
    #[DataProvider('intervalKeys')]
    public function testAnUnsetKeyFallsBackToTheIntervalTheTemplateUsedToHardcode(
        string $controller,
        string $method,
        string $key,
        int $default,
    ): void {
        $this->withConfig($key, null, function () use ($controller, $method, $default): void {
            self::assertSame($default, $this->intervalFrom($controller, $method));
        });
    }

    /**
     * The value has to survive the trip: the controller must name
     * `pollIntervalMs` in the `render()` call for that screen, and the template
     * must declare its constant from that variable.
     *
     * @requirement NFR-76
     */
    #[DataProvider('timers')]
    public function testEachPolledScreenIsHandedItsIntervalAndUsesIt(string $template, string $timer): void
    {
        $source = $this->read(self::templatesDir() . '/' . $template);

        self::assertSame(
            1,
            substr_count($source, 'const POLL_INTERVAL_MS = <?= $pollIntervalMs ?>;'),
            $template . ' does not declare POLL_INTERVAL_MS from the value its controller passes.',
        );
        self::assertStringContainsString(
            $timer,
            $source,
            $template . ' no longer drives its timer from POLL_INTERVAL_MS.',
        );
    }

    /**
     * @requirement NFR-76
     */
    #[DataProvider('timers')]
    public function testTheControllerThatRendersThePolledScreenPassesTheInterval(string $template, string $timer): void
    {
        self::assertStringContainsString('POLL_INTERVAL_MS', $timer);

        $controllers = [];

        foreach ($this->phpFilesIn(\dirname(__DIR__, 2) . '/src/Controllers') as $file) {
            $source = $this->read($file->getPathname());

            if (str_contains($source, "'" . $template . "'")) {
                $controllers[] = $source;
            }
        }

        self::assertCount(
            1,
            $controllers,
            'Expected exactly one controller to render ' . $template . '.',
        );
        self::assertStringContainsString(
            "'pollIntervalMs' => \$this->",
            $controllers[0],
            'The controller for ' . $template . ' does not pass an interval.',
        );
    }

    // ── No template hardcodes an interval ─────────────────────────────────────

    /**
     * @requirement NFR-76
     */
    public function testNoTemplateHardcodesAPollInterval(): void
    {
        $offenders = [];
        $withTimers = 0;

        foreach ($this->phpFilesIn(self::templatesDir()) as $file) {
            if (! str_contains($this->read($file->getPathname()), 'setInterval')) {
                continue;
            }

            ++$withTimers;

            foreach ($this->hardcodedIntervalsIn($file->getPathname()) as $offence) {
                $offenders[] = $file->getFilename() . ':' . $offence;
            }
        }

        self::assertGreaterThan(0, $withTimers, 'Nothing scanned — no template sets a timer at all.');
        self::assertSame(
            [],
            $offenders,
            'These timers hardcode their interval instead of using the configured one:'
            . PHP_EOL . implode(PHP_EOL, $offenders),
        );
    }

    /** The guard above is vacuous unless it can see a hardcoded delay, so it is shown two. */
    public function testTheIntervalScanRecognisesBothShapes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eduqr-interval-') . '.php';
        file_put_contents(
            $file,
            "<?php ?>\n<script>\n"
            . "setInterval(tick, 1000);\n"
            . "setInterval(function () {\n"
            . "    poll(a, b);\n"
            . "}, 2500);\n"
            . "setInterval(tick, POLL_INTERVAL_MS);\n"
            . "</script>\n",
        );

        try {
            self::assertSame(
                ['3 — 1000', '4 — 2500'],
                $this->hardcodedIntervalsIn($file),
            );
        } finally {
            unlink($file);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Every `setInterval()` delay written as a numeric literal, as
     * `<line> — <delay>`.
     *
     * The arguments are split by walking the brackets rather than by a regular
     * expression, because the second-commonest shape in this codebase passes an
     * inline function whose own body contains both commas and newlines.
     *
     * @return list<string>
     */
    private function hardcodedIntervalsIn(string $path): array
    {
        $source = $this->read($path);
        $length = \strlen($source);
        $offenders = [];
        $offset = 0;

        while (($at = strpos($source, 'setInterval', $offset)) !== false) {
            $offset = $at + 11;
            $open = strpos($source, '(', $at);

            if ($open === false) {
                break;
            }

            $depth = 0;
            $args = [''];

            for ($i = $open; $i < $length; ++$i) {
                $char = $source[$i];

                if ($char === '(' || $char === '[' || $char === '{') {
                    ++$depth;

                    if ($depth === 1) {
                        continue;
                    }
                } elseif ($char === ')' || $char === ']' || $char === '}') {
                    --$depth;

                    if ($depth === 0) {
                        break;
                    }
                } elseif ($char === ',' && $depth === 1) {
                    $args[] = '';

                    continue;
                }

                $args[\count($args) - 1] .= $char;
            }

            foreach (\array_slice($args, 1) as $argument) {
                $argument = trim($argument);

                if (preg_match('/^\d+$/', $argument) === 1) {
                    $offenders[] = (substr_count($source, "\n", 0, $at) + 1) . ' — ' . $argument;
                }
            }
        }

        return $offenders;
    }

    /**
     * The interval a controller would hand its template.
     *
     * The controller is built without its constructor because the constructor
     * asks the container for repositories, and reading a configuration key
     * needs none of them.
     *
     * @param class-string $controller
     */
    private function intervalFrom(string $controller, string $method): int
    {
        $reflection = new \ReflectionMethod($controller, $method);

        return $reflection->invoke((new \ReflectionClass($controller))->newInstanceWithoutConstructor());
    }

    /**
     * Run `$body` with one configuration key set to `$value`, or absent when
     * `$value` is null, and put everything back afterwards.
     *
     * `Config::get()` prefers the values parsed out of `.env` over `getenv()`,
     * so a `putenv()` alone cannot make a key that `.env` declares look unset.
     * Both places are moved, and both are restored.
     */
    private function withConfig(string $key, ?string $value, callable $body): void
    {
        $data = new \ReflectionProperty(Config::class, 'data');
        /** @var array<string, string> $original */
        $original = $data->getValue();
        $hadEnv = getenv($key);

        $changed = $original;

        if ($value === null) {
            unset($changed[$key]);
            putenv($key);
        } else {
            $changed[$key] = $value;
            putenv($key . '=' . $value);
        }

        $data->setValue(null, $changed);

        try {
            $body();
        } finally {
            $data->setValue(null, $original);

            if ($hadEnv === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $hadEnv);
            }
        }
    }

    private static function templatesDir(): string
    {
        return \dirname(__DIR__, 2) . '/templates';
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Could not read ' . $path);

        return $source;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }
}
