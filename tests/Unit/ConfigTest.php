<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    public function testGetReturnsDefaultWhenKeyMissing(): void
    {
        $result = Config::get('__NONEXISTENT_KEY__', 'default_val');
        $this->assertSame('default_val', $result);
    }

    public function testBoolReturnsTrueForTrueString(): void
    {
        putenv('TEST_BOOL_VAL=true');
        $this->assertTrue(Config::bool('TEST_BOOL_VAL'));
        putenv('TEST_BOOL_VAL');
    }

    public function testBoolReturnsFalseForFalseString(): void
    {
        putenv('TEST_BOOL_VAL=false');
        $this->assertFalse(Config::bool('TEST_BOOL_VAL'));
        putenv('TEST_BOOL_VAL');
    }

    public function testIntReturnsIntValue(): void
    {
        putenv('TEST_INT_VAL=42');
        $this->assertSame(42, Config::int('TEST_INT_VAL'));
        putenv('TEST_INT_VAL');
    }

    public function testRequireThrowsOnMissingKey(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::require('__DEFINITELY_MISSING__');
    }
}
