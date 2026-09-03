<?php

declare(strict_types=1);

namespace EduQR\Support;

/**
 * Locale-independent case folding for comparing user-authored text (NFR-77).
 *
 * Why this exists
 * ---------------
 * Turkish has four i-letters: dotted i/İ and dotless ı/I. Neither PHP's
 * mb_strtolower() nor SQL LOWER() applies Turkish casing rules, so a naive
 * case-insensitive comparison gives the WRONG ANSWER, not merely odd display:
 *
 *   mb_strtolower('İ')  → "i" + U+0307 COMBINING DOT ABOVE  (≠ "i")
 *   mb_strtoupper('i')  → "I"                                (≠ "İ")
 *   SQLite  LOWER('İ')  → "İ"   (LOWER is ASCII-only there)
 *   MySQL   LOWER('İ')  → "i" + U+0307 under utf8mb4_unicode_ci
 *
 * The folding rule
 * ----------------
 * Every i-variant — i, I, ı (U+0131), İ (U+0130), and a stray U+0307 left by a
 * Unicode lowercase — collapses to plain ASCII "i". The rest of the string is
 * lowercased normally.
 *
 * This rule is deliberately NOT locale-dependent. A locale-dependent fold would
 * make the same two strings compare equal or unequal depending on who is
 * looking, which is unacceptable for grading: a Turkish student's answer must
 * score the same whether the report is opened in Turkish or in English.
 *
 * A true Turkish fold would map I→ı, which would stop an English "I" from
 * matching "i". A true invariant fold maps İ→i+U+0307, which stops Turkish
 * "İstanbul" from matching "istanbul". Neither can satisfy both languages,
 * because once a string is lowercased there is no way to know whether an
 * uppercase "I" came from "i" or from "ı". Collapsing the whole i-family into
 * one equivalence class is the only choice that never marks a correct answer
 * wrong in either language, so that is the choice made here.
 *
 * The accepted cost: the Turkish minimal pair ı/i is merged, so "sıra" and
 * "sira" compare equal. That over-merges — it can ask a student to pick a
 * different nickname, or group two word-cloud terms together. It can never
 * mark a correct answer wrong, which is the failure mode that matters.
 *
 * Rules of use
 * ------------
 *  - Comparison only. Never store or display a folded string; the original text
 *    is what the user sees.
 *  - Fold BOTH sides of every comparison, including hardcoded token lists
 *    ("hayır", "doğru") — they contain i-variants too.
 */
final class TextFold
{
    /** İ U+0130, ı U+0131, plus both ASCII cases, all collapse to "i". */
    private const I_FAMILY = [
        "\u{0130}" => 'i',
        "\u{0131}" => 'i',
        'I' => 'i',
        'i' => 'i',
    ];

    /** Left behind by a Unicode lowercase of İ, and present in decomposed input. */
    private const COMBINING_DOT_ABOVE = "\u{0307}";

    /**
     * Fold text into a canonical comparison key. Not for storage or display.
     */
    public static function forComparison(string $text): string
    {
        // Map the i-family BEFORE lowercasing, so mb_strtolower() never gets the
        // chance to emit the combining dot in the first place.
        $mapped = strtr($text, self::I_FAMILY);

        $lowered = mb_strtolower($mapped, 'UTF-8');

        // Defensive: input may already have arrived decomposed as "I" + U+0307.
        return str_replace(self::COMBINING_DOT_ABOVE, '', $lowered);
    }

    /**
     * Fold, trim, and collapse internal whitespace — the key used when comparing
     * whole user-entered values such as nicknames or typed answers.
     */
    public static function forComparisonNormalized(string $text): string
    {
        $folded = self::forComparison(trim($text));

        return (string) preg_replace('/\s+/u', ' ', $folded);
    }

    /**
     * True when two pieces of user-authored text are the same ignoring case,
     * surrounding whitespace, and i-variant spelling.
     */
    public static function equals(string $left, string $right): bool
    {
        return self::forComparisonNormalized($left) === self::forComparisonNormalized($right);
    }
}
