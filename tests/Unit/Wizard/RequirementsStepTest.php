<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Wizard;

use EduQR\Support\Wizard\Steps\RequirementsStep;
use PHPUnit\Framework\TestCase;

class RequirementsStepTest extends TestCase
{
    public function testPhpVersionPassesFor82Plus(): void
    {
        $step = new RequirementsStep('/tmp');
        // Test ortamı PHP 8.2+ ile çalışıyor
        $this->assertTrue($step->checkPhpVersion());
    }

    public function testJsonExtensionAlwaysAvailable(): void
    {
        $step = new RequirementsStep('/tmp');
        $this->assertTrue($step->checkExtension('json'));
    }

    public function testPdoMysqlExtensionAvailable(): void
    {
        $step = new RequirementsStep('/tmp');
        // XAMPP 8 ve Ubuntu php8.2-mysql paketinde pdo_mysql mevcut
        $this->assertTrue($step->checkExtension('pdo_mysql'));
    }

    public function testNonExistentExtensionReturnsFalse(): void
    {
        $step = new RequirementsStep('/tmp');
        $this->assertFalse($step->checkExtension('nonexistent_extension_xyz_abc'));
    }

    public function testCheckVendorTrueWhenDirExists(): void
    {
        $tmpDir = sys_get_temp_dir() . '/req_vendor_test_' . uniqid();
        mkdir($tmpDir . '/vendor', 0755, true);

        $step = new RequirementsStep($tmpDir);
        $this->assertTrue($step->checkVendor());

        rmdir($tmpDir . '/vendor');
        rmdir($tmpDir);
    }

    public function testCheckVendorFalseWhenDirMissing(): void
    {
        $step = new RequirementsStep('/tmp/nonexistent_project_' . uniqid());
        $this->assertFalse($step->checkVendor());
    }

    public function testTitleReturnsNonEmptyString(): void
    {
        $step = new RequirementsStep('/tmp');
        $this->assertNotEmpty($step->title());
    }
}
