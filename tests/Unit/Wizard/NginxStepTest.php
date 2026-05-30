<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Wizard;

use EduQR\Support\Wizard\Steps\NginxStep;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class NginxStepTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/nginx_step_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Remove files inside deploy/ first (testRenderUsesDefaultTemplatePath)
        foreach (glob($this->tmpDir . '/deploy/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->tmpDir . '/deploy')) {
            rmdir($this->tmpDir . '/deploy');
        }
        // Remove top-level files (skip directories)
        foreach (glob($this->tmpDir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        rmdir($this->tmpDir);
    }

    private function writeTpl(string $content): string
    {
        $path = $this->tmpDir . '/test.conf.template';
        file_put_contents($path, $content);

        return $path;
    }

    public function testRenderReplacesDomainPlaceholder(): void
    {
        $tpl = $this->writeTpl('server_name {{DOMAIN}};');
        $step = new NginxStep('/fake/root', $tpl);

        $output = $step->render(['DOMAIN' => 'eduqr.example.com']);

        $this->assertStringContainsString('server_name eduqr.example.com;', $output);
    }

    public function testRenderReplacesFpmSocketPlaceholder(): void
    {
        $tpl = $this->writeTpl('fastcgi_pass {{FPM_SOCKET}};');
        $step = new NginxStep('/fake/root', $tpl);

        $output = $step->render(['FPM_SOCKET' => 'unix:/run/php/php8.2-fpm.sock']);

        $this->assertStringContainsString('fastcgi_pass unix:/run/php/php8.2-fpm.sock;', $output);
    }

    public function testRenderReplacesAllKnownPlaceholders(): void
    {
        $tpl = $this->writeTpl('{{DOMAIN}} {{PROJECT_ROOT}} {{FPM_SOCKET}} {{SSL_CERT}} {{SSL_KEY}}');
        $step = new NginxStep('/fake/root', $tpl);

        $output = $step->render([
            'DOMAIN' => 'test.com',
            'PROJECT_ROOT' => '/var/www/eduqr',
            'FPM_SOCKET' => 'unix:/run/php/php8.2-fpm.sock',
            'SSL_CERT' => '/etc/letsencrypt/live/test.com/fullchain.pem',
            'SSL_KEY' => '/etc/letsencrypt/live/test.com/privkey.pem',
        ]);

        $this->assertSame(
            'test.com /var/www/eduqr unix:/run/php/php8.2-fpm.sock'
            . ' /etc/letsencrypt/live/test.com/fullchain.pem'
            . ' /etc/letsencrypt/live/test.com/privkey.pem',
            $output,
        );
    }

    public function testRenderLeavesUnknownPlaceholdersIntact(): void
    {
        $tpl = $this->writeTpl('{{KNOWN}} {{UNKNOWN_KEY}}');
        $step = new NginxStep('/fake/root', $tpl);

        $output = $step->render(['KNOWN' => 'replaced']);

        $this->assertStringContainsString('replaced', $output);
        $this->assertStringContainsString('{{UNKNOWN_KEY}}', $output);
    }

    public function testRenderThrowsRuntimeExceptionIfTemplateNotFound(): void
    {
        $step = new NginxStep('/fake/root', '/nonexistent/path/template.conf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Template okunamadı/');

        $step->render([]);
    }

    public function testRenderUsesDefaultTemplatePath(): void
    {
        // Deploy dizini ve şablonu tmpDir içinde oluştur
        $deployDir = $this->tmpDir . '/deploy';
        mkdir($deployDir, 0755, true);
        file_put_contents($deployDir . '/nginx.conf.template', 'server_name {{DOMAIN}};');

        $step = new NginxStep($this->tmpDir); // templatePath verilmedi → varsayılan
        $output = $step->render(['DOMAIN' => 'auto.example.com']);

        $this->assertStringContainsString('server_name auto.example.com;', $output);
    }

    public function testTitleReturnsNonEmptyString(): void
    {
        $step = new NginxStep('/tmp');
        $this->assertNotEmpty($step->title());
    }
}
