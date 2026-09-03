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
