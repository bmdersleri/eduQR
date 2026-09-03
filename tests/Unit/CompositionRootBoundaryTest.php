<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The composition root is the only place that builds the object graph — NFR-80.
 *
 * ContainerTest proves the container can build everything. This test proves
 * nobody else does: it reads the delivery layer as text and fails on any inline
 * `new SomethingService(...)`, `new SomethingRepository(...)` or
 * `Something::fromConfig()`. Text is the right tool here because a template is
 * never loaded by the autoloader — it is included by the router at request time,
 * so reflection cannot see it.
 */
final class CompositionRootBoundaryTest extends TestCase
{
    private const INLINE_CONSTRUCTION = '/\bnew\s+[A-Za-z_\\\\]*(?:Service|Repository)\s*\(|::fromConfig\s*\(/';

    /**
     * @requirement NFR-80
     */
    public function testNoApiControllerBuildsACollaboratorInline(): void
    {
        $this->assertNoInlineConstruction(\dirname(__DIR__, 2) . '/src/Controllers');
    }

    /**
     * @requirement NFR-80
     */
    public function testNoTemplateBuildsACollaboratorInline(): void
    {
        $this->assertNoInlineConstruction(\dirname(__DIR__, 2) . '/templates');
    }

    /**
     * A service does not build its collaborators either.
     *
     * The delivery layer was the obvious place to look, but the longest-lived
     * violation was a service: `ReportService::extractThemes()` fell back to
     * `OpenTextThemeExtractionService::fromConfig()` whenever no extractor had
     * been injected, so the object graph had a second, hidden root. T-1130
     * retired it by making the extractor a required constructor parameter of
     * `ResultsService`; this keeps it retired.
     *
     * The scan is token-based rather than line-based here because the services
     * that were split now carry docblocks *describing* the fallback they no
     * longer have, and a text match cannot tell a comment from a call.
     *
     * @requirement NFR-80
     */
    public function testNoServiceBuildsACollaboratorInline(): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFilesIn(\dirname(__DIR__, 2) . '/src/Services') as $file) {
            ++$scanned;

            foreach ($this->constructionCallsIn($file->getPathname()) as $call) {
                $offenders[] = $file->getFilename() . ':' . $call;
            }
        }

        self::assertGreaterThan(0, $scanned, 'Nothing scanned — src/Services is missing.');
        self::assertSame(
            [],
            $offenders,
            'These services build a collaborator instead of taking one:'
            . PHP_EOL . implode(PHP_EOL, $offenders),
        );
    }

    /** The guard above is vacuous unless it can see a call, so it is shown one. */
    public function testTheServiceScanRecognisesBothShapes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'eduqr-boundary-') . '.php';
        file_put_contents(
            $file,
            "<?php\n// new ThemeService() in a comment does not count\n"
            . "\$a = new ThemeService(new ThemeRepository());\n"
            . "\$b = ThemeService::fromConfig();\n",
        );

        try {
            self::assertSame(
                [
                    '3 — new ThemeService',
                    '3 — new ThemeRepository',
                    '4 — ThemeService::fromConfig',
                ],
                $this->constructionCallsIn($file),
            );
        } finally {
            unlink($file);
        }
    }

    private function assertNoInlineConstruction(string $directory): void
    {
        $offenders = [];
        $scanned = 0;

        foreach ($this->phpFilesIn($directory) as $file) {
            ++$scanned;
            $lines = file($file->getPathname(), FILE_IGNORE_NEW_LINES);

            if ($lines === false) {
                self::fail('Could not read ' . $file->getPathname());
            }

            foreach ($lines as $index => $line) {
                if (preg_match(self::INLINE_CONSTRUCTION, $line) === 1) {
                    $offenders[] = $file->getFilename() . ':' . ($index + 1) . ' — ' . trim($line);
                }
            }
        }

        self::assertGreaterThan(0, $scanned, 'Nothing scanned — the path is wrong: ' . $directory);
        self::assertSame(
            [],
            $offenders,
            'These call sites build a collaborator instead of asking EduQR\Container for one:'
            . PHP_EOL . implode(PHP_EOL, $offenders),
        );
    }

    /**
     * Every `new SomethingService(` / `new SomethingRepository(` and every
     * `Something::fromConfig(` a file really executes, as `<line> — <call>`.
     *
     * Reading tokens rather than lines is what lets a class document the
     * fallback it used to have without tripping the guard against having one.
     *
     * @return list<string>
     */
    private function constructionCallsIn(string $path): array
    {
        $source = file_get_contents($path);
        self::assertNotFalse($source, 'Could not read ' . $path);

        $tokens = token_get_all($source);
        $count = \count($tokens);
        $calls = [];

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (! \is_array($token)) {
                continue;
            }

            if ($token[0] === T_NEW) {
                $class = $this->nextName($tokens, $i, $count);

                if ($class !== null && preg_match('/(?:Service|Repository)$/', $class) === 1) {
                    $calls[] = $token[2] . ' — new ' . $class;
                }

                continue;
            }

            if ($token[0] === T_DOUBLE_COLON
                && isset($tokens[$i + 1])
                && \is_array($tokens[$i + 1])
                && $tokens[$i + 1][1] === 'fromConfig') {
                $calls[] = $token[2] . ' — ' . $this->previousName($tokens, $i) . '::fromConfig';
            }
        }

        return $calls;
    }

    /** The class name a `new` applies to, ignoring whitespace and leading `\`. */
    private function nextName(array $tokens, int $from, int $count): ?string
    {
        for ($j = $from + 1; $j < $count; ++$j) {
            if (\is_array($tokens[$j])
                && \in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_NS_SEPARATOR], true)) {
                continue;
            }

            return \is_array($tokens[$j]) ? ltrim($tokens[$j][1], '\\') : null;
        }

        return null;
    }

    private function previousName(array $tokens, int $from): string
    {
        for ($j = $from - 1; $j >= 0; --$j) {
            if (\is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                continue;
            }

            return \is_array($tokens[$j]) ? ltrim($tokens[$j][1], '\\') : (string) $tokens[$j];
        }

        return '?';
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
