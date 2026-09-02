<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\I18n\I18nMiddleware;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class I18nMiddlewareTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = sys_get_temp_dir() . '/eduqr_i18n_middleware_' . uniqid();
        mkdir($this->fixtures, 0777, true);
        file_put_contents($this->fixtures . '/en.json', '{}');
        file_put_contents($this->fixtures . '/tr.json', '{}');

        $_GET = [];
        $_COOKIE = [];
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE'], $_SERVER['HTTPS']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixtures . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->fixtures);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_resolve_reads_eduqr_locale_cookie_FR82(): void
    {
        $_COOKIE['eduqr_locale'] = 'tr';

        $locale = I18nMiddleware::resolve($this->fixtures);

        $this->assertSame('tr', $locale);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_resolve_writes_script_readable_locale_cookie_NFR23(): void
    {
        $_GET['lang'] = 'tr';
        $writtenCookie = null;

        I18nMiddleware::resolve(
            $this->fixtures,
            static function (string $name, string $value, array $options) use (&$writtenCookie): bool {
                $writtenCookie = compact('name', 'value', 'options');

                return true;
            }
        );

        $this->assertSame('eduqr_locale', $writtenCookie['name'] ?? null);
        $this->assertSame('tr', $writtenCookie['value'] ?? null);
        $this->assertTrue($writtenCookie['options']['secure'] ?? false);
        $this->assertFalse($writtenCookie['options']['httponly'] ?? true);
        $this->assertSame('Lax', $writtenCookie['options']['samesite'] ?? null);
        $this->assertSame('/', $writtenCookie['options']['path'] ?? null);
    }
}
