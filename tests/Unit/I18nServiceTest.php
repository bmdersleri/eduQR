<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit;

use EduQR\I18n\I18nService;
use PHPUnit\Framework\TestCase;

final class I18nServiceTest extends TestCase
{
    private string $fixtures;

    protected function setUp(): void
    {
        $this->fixtures = sys_get_temp_dir() . '/eduqr_i18n_' . uniqid();
        mkdir($this->fixtures, 0777, true);

        file_put_contents($this->fixtures . '/en.json', (string) json_encode([
            'common.hello' => 'Hello',
            'common.welcome' => 'Welcome, {name}!',
            'items.count.one' => 'One item',
            'items.count.other' => '{count} items',
        ]));

        file_put_contents($this->fixtures . '/tr.json', (string) json_encode([
            'common.hello' => 'Merhaba',
            'common.welcome' => 'Hoş geldiniz, {name}!',
        ]));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixtures . '/*.json') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->fixtures);
    }

    public function testTranslateEnglishKey(): void
    {
        I18nService::init($this->fixtures, 'en');
        $this->assertSame('Hello', I18nService::translate('common.hello'));
    }

    public function testTranslateWithPlaceholder(): void
    {
        I18nService::init($this->fixtures, 'en');
        $this->assertSame('Welcome, Alice!', I18nService::translate('common.welcome', ['name' => 'Alice']));
    }

    public function testTranslateTurkish(): void
    {
        I18nService::init($this->fixtures, 'tr');
        $this->assertSame('Merhaba', I18nService::translate('common.hello'));
    }

    public function testFallbackToEnglishForMissingTurkishKey(): void
    {
        I18nService::init($this->fixtures, 'tr');
        // items.count.one exists in en but not tr
        $this->assertSame('One item', I18nService::translate('items.count.one'));
    }

    public function testFallbackToKeyForCompletelyMissingKey(): void
    {
        I18nService::init($this->fixtures, 'en');
        $this->assertSame('nonexistent.key', I18nService::translate('nonexistent.key'));
    }

    public function testTranslatePluralSingular(): void
    {
        I18nService::init($this->fixtures, 'en');
        $this->assertSame('One item', I18nService::translatePlural('items.count', 1));
    }

    public function testTranslatePluralMultiple(): void
    {
        I18nService::init($this->fixtures, 'en');
        $this->assertSame('5 items', I18nService::translatePlural('items.count', 5));
    }

    public function testGetLocale(): void
    {
        I18nService::init($this->fixtures, 'tr');
        $this->assertSame('tr', I18nService::getLocale());
    }

    public function testAvailableLocales(): void
    {
        $this->assertSame(['en', 'tr'], I18nService::availableLocales());
    }
}
