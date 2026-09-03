<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Controllers;

use EduQR\I18n\I18nService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A page title is escaped once, where it is rendered — NFR-84.
 *
 * Eleven routes escaped their title in the controller and then handed it to a
 * layout that escapes `$pageTitle` again, so a course called
 * `Ölçme & Değerlendirme` reached the browser as `Ölçme &amp;amp;
 * Değerlendirme`. It went unseen for as long as it did because every title in
 * the test fixtures was alphanumeric.
 *
 * The two halves of the rule need two different guards. The layout end is
 * behavioural: render the real file and count. The controller end cannot be —
 * a controller escaping its title produces correct-looking output right up
 * until a title contains `&`, `'` or `"`, so the guard has to read the source.
 */
final class PageTitleEscapingTest extends TestCase
{
    protected function setUp(): void
    {
        I18nService::init(\dirname(__DIR__, 3) . '/locales', 'en');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function layouts(): array
    {
        return [
            'admin' => ['admin'],
            'public' => ['public'],
            'projector' => ['projector'],
        ];
    }

    /**
     * @requirement NFR-84
     */
    #[DataProvider('layouts')]
    public function testALayoutEscapesTheTitleExactlyOnce(string $layout): void
    {
        $html = $this->renderLayout($layout, 'Ölçme & Değerlendirme — eduQR');

        self::assertStringContainsString(
            '<title>Ölçme &amp; Değerlendirme — eduQR</title>',
            $html,
            "layouts/{$layout}.php did not escape the title exactly once.",
        );
        self::assertStringNotContainsString('&amp;amp;', $html);
    }

    /**
     * @requirement NFR-84
     */
    #[DataProvider('layouts')]
    public function testALayoutStillEscapesAHostileTitle(string $layout): void
    {
        $html = $this->renderLayout($layout, '</title><script>alert(1)</script>');

        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /**
     * No controller pre-escapes the title it passes to `render()`.
     *
     * The title is `render()`'s third argument, and `titleWithAppName()` only
     * ever appears inside one, so both shapes are checked the same way: find
     * the call, walk to the argument, and fail if `htmlspecialchars` is in it.
     *
     * @requirement NFR-84
     */
    public function testNoControllerPreEscapesAPageTitle(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFilesIn(\dirname(__DIR__, 3) . '/src/Controllers') as $file) {
            ++$scanned;

            foreach ($this->preEscapedTitlesIn($file->getPathname()) as $call) {
                $offenders[] = $file->getFilename() . ':' . $call;
            }
        }

        self::assertGreaterThan(0, $scanned, 'Nothing scanned — src/Controllers is missing.');
        self::assertSame(
            [],
            $offenders,
            'These titles are escaped by the controller and again by the layout:'
            . PHP_EOL . implode(PHP_EOL, $offenders),
        );
    }

    /** The guard above is vacuous unless it can see one, so it is shown both shapes. */
    public function testTheTitleScanRecognisesBothShapes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eduqr-title-') . '.php';
        file_put_contents(
            $file,
            "<?php\n"
            . "\$this->render('a.php', ['x' => htmlspecialchars(\$y, ENT_QUOTES, 'UTF-8')], \$t);\n"
            . "\$this->render('b.php', [], self::titleWithAppName(htmlspecialchars(\$t, ENT_QUOTES, 'UTF-8')));\n"
            . "\$this->render('c.php', [], htmlspecialchars(t('k'), ENT_QUOTES, 'UTF-8') . ' — ' . \$t);\n",
        );

        try {
            // Line 2 escapes a *data* member, which is the template's business
            // and not a title; only lines 3 and 4 are pre-escaped titles.
            self::assertSame(['3', '4'], $this->preEscapedTitlesIn($file));
        } finally {
            unlink($file);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** The real layout file, rendered with the smallest fixture it accepts. */
    private function renderLayout(string $layout, string $pageTitle): string
    {
        $path = \dirname(__DIR__, 3) . '/templates/layouts/' . $layout . '.php';
        self::assertFileExists($path);

        $data = [
            'pageTitle' => $pageTitle,
            'content' => '<p>body</p>',
            'instructor' => ['id' => 1, 'name' => 'Ayşe', 'email' => 'a@example.test'],
            'csrfToken' => 'token',
            'flashMessage' => null,
            'flashType' => null,
        ];

        ob_start();

        try {
            (static function (array $data) use ($path): void {
                extract($data, EXTR_SKIP);
                require $path;
            })($data);

            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();

            throw $e;
        }
    }

    /**
     * The line of every `render()` call whose title argument escapes.
     *
     * @return list<string>
     */
    private function preEscapedTitlesIn(string $path): array
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Could not read ' . $path);

        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn ($t): bool => ! \is_array($t)
                || ! \in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $lines = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            if (! \is_array($tokens[$i]) || $tokens[$i][1] !== 'render' || ($tokens[$i + 1] ?? null) !== '(') {
                continue;
            }

            $title = $this->argument($tokens, $i + 1, $count, 2);

            foreach ($title as $token) {
                if (\is_array($token) && $token[1] === 'htmlspecialchars') {
                    $lines[] = (string) $tokens[$i][2];

                    break;
                }
            }
        }

        return $lines;
    }

    /**
     * The tokens of one argument of the call whose `(` sits at `$open`.
     *
     * Nested calls and array literals are stepped over by depth, so a comma
     * inside `htmlspecialchars($t, ENT_QUOTES, 'UTF-8')` does not end an
     * argument.
     *
     * @param  list<array{int, string, int}|string>  $tokens
     * @return list<array{int, string, int}|string>
     */
    private function argument(array $tokens, int $open, int $count, int $index): array
    {
        $depth = 0;
        $at = 0;
        $collected = [];

        for ($i = $open; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (\in_array($token, ['(', '[', '{'], true)) {
                ++$depth;

                if ($depth === 1) {
                    continue;
                }
            } elseif (\in_array($token, [')', ']', '}'], true)) {
                --$depth;

                if ($depth === 0) {
                    break;
                }
            } elseif ($token === ',' && $depth === 1) {
                ++$at;

                continue;
            }

            if ($at === $index) {
                $collected[] = $token;
            }
        }

        return $collected;
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
