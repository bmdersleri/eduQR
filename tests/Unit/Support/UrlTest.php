<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Support;

use EduQR\Config;
use EduQR\Support\Url;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class UrlTest extends TestCase
{
    private function withAppUrl(string $appUrl, callable $callback): mixed
    {
        $ref = new ReflectionClass(Config::class);
        $data = $ref->getProperty('data');
        $loaded = $ref->getProperty('loaded');

        $originalData = $data->getValue();
        $originalLoaded = $loaded->getValue();

        Url::reset();
        $data->setValue(null, array_merge($originalData, ['APP_URL' => $appUrl]));

        try {
            return $callback();
        } finally {
            $data->setValue(null, $originalData);
            $loaded->setValue(null, $originalLoaded);
            Url::reset();
        }
    }

    public function testBasePathFromAppUrlSupportsSubfolderNFR15(): void
    {
        $this->assertSame('/eduqr', Url::basePathFromAppUrl('http://example.test/eduqr'));
        $this->assertSame('', Url::basePathFromAppUrl('http://example.test'));
    }

    public function testPathFromBasePathPrefixesMountPointNFR15(): void
    {
        $this->assertSame('/eduqr/admin/courses', Url::pathFromBasePath('/eduqr', '/admin/courses'));
        $this->assertSame('/admin/courses', Url::pathFromBasePath('', '/admin/courses'));
    }

    public function testAbsoluteUsesConfiguredAppUrlNFR15(): void
    {
        $this->withAppUrl('http://example.test/eduqr', function (): void {
            $this->assertSame('http://example.test/eduqr/join/ABC123', Url::absolute('/join/ABC123'));
        });
    }

    public function testStripBasePathRemovesConfiguredMountPointNFR15(): void
    {
        $this->assertSame('/tr/login', Url::stripBasePath('/eduqr/tr/login', '/eduqr'));
        $this->assertSame('/', Url::stripBasePath('/eduqr', '/eduqr'));
    }
}
