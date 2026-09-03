<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Support;

use EduQR\Support\TextFold;
use PHPUnit\Framework\TestCase;

/**
 * NFR-77 — case-insensitive comparison of user-authored text must be correct
 * for Turkish and must not vary with the active locale.
 */
class TextFoldTest extends TestCase
{
    /** The behaviour PHP gives you by default, and why this class exists. */
    public function testNaiveLowercaseIsNotTurkishCorrect_NFR77(): void
    {
        $this->assertNotSame('istanbul', mb_strtolower('İstanbul', 'UTF-8'));
        $this->assertStringContainsString("\u{0307}", mb_strtolower('İ', 'UTF-8'));
    }

    public function testFoldsDottedCapitalIToPlainI_NFR77(): void
    {
        $this->assertSame('istanbul', TextFold::forComparison('İstanbul'));
        $this->assertStringNotContainsString("\u{0307}", TextFold::forComparison('İ'));
    }

    public function testFoldsDotlessIFamilyToPlainI_NFR77(): void
    {
        $this->assertSame('i', TextFold::forComparison('ı'));
        $this->assertSame('i', TextFold::forComparison('I'));
        $this->assertSame('i', TextFold::forComparison('i'));
        $this->assertSame('i', TextFold::forComparison('İ'));
    }

    public function testFoldsDecomposedDottedI_NFR77(): void
    {
        // "I" + U+0307, the decomposed spelling of İ.
        $this->assertSame('i', TextFold::forComparison("I\u{0307}"));
    }

    /** An English answer differing only in i-casing must still compare equal. */
    public function testEnglishICasingStillMatches_NFR77(): void
    {
        $this->assertTrue(TextFold::equals('MITOCHONDRIA', 'mitochondria'));
        $this->assertTrue(TextFold::equals('Insulin', 'insulin'));
    }

    public function testTurkishICasingMatches_NFR77(): void
    {
        $this->assertTrue(TextFold::equals('İstanbul', 'istanbul'));
        $this->assertTrue(TextFold::equals('IZMIR', 'İzmir'));
        $this->assertTrue(TextFold::equals('ışık', 'IŞIK'));
    }

    /**
     * The fold must be a pure function of its input — the same two strings
     * compare the same way whichever locale is active, so a graded answer never
     * depends on who opens the report.
     */
    public function testFoldDoesNotVaryWithActiveLocale_NFR77(): void
    {
        $previous = setlocale(LC_ALL, '0');

        setlocale(LC_ALL, 'tr_TR.UTF-8', 'tr_TR', 'Turkish');
        $turkish = TextFold::forComparison('İstanbul');

        setlocale(LC_ALL, 'en_US.UTF-8', 'en_US', 'English');
        $english = TextFold::forComparison('İstanbul');

        setlocale(LC_ALL, $previous !== false ? $previous : 'C');

        $this->assertSame($turkish, $english);
        $this->assertSame('istanbul', $turkish);
    }

    public function testGenuinelyDifferentTextStillDiffers_NFR77(): void
    {
        $this->assertFalse(TextFold::equals('İstanbul', 'Ankara'));
        $this->assertFalse(TextFold::equals('istanbul', 'istanbulspor'));
    }

    public function testNormalizedFormTrimsAndCollapsesWhitespace(): void
    {
        $this->assertSame('ali can', TextFold::forComparisonNormalized("  Ali \t Can  "));
        $this->assertTrue(TextFold::equals('  İSTANBUL ', 'istanbul'));
    }

    public function testNonLatinTextIsLoweredNormally(): void
    {
        $this->assertSame('şeyma', TextFold::forComparison('ŞEYMA'));
        $this->assertSame('öğrenci', TextFold::forComparison('ÖĞRENCİ'));
    }
}
