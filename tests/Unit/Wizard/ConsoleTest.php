<?php

declare(strict_types=1);

namespace EduQR\Tests\Unit\Wizard;

use EduQR\Support\Wizard\Console;
use PHPUnit\Framework\TestCase;

class ConsoleTest extends TestCase
{
    /** @return array{Console, resource} */
    private function makeConsole(string $input): array
    {
        $stdin = fopen('php://memory', 'r+');
        $stdout = fopen('php://memory', 'r+');
        fwrite($stdin, $input);
        rewind($stdin);
        $console = new Console($stdin, $stdout);

        return [$console, $stdout];
    }

    private function readOutput(mixed $stdout): string
    {
        rewind($stdout);

        return (string) stream_get_contents($stdout);
    }

    public function testPromptReturnsInputWhenProvided(): void
    {
        [$console] = $this->makeConsole("merhaba\n");
        $result = $console->prompt('Bir şey girin', 'varsayılan');
        $this->assertSame('merhaba', $result);
    }

    public function testPromptReturnsDefaultWhenInputIsEmpty(): void
    {
        [$console] = $this->makeConsole("\n");
        $result = $console->prompt('Bir şey girin', 'varsayılan');
        $this->assertSame('varsayılan', $result);
    }

    public function testPromptReturnsEmptyStringWhenNoDefault(): void
    {
        [$console] = $this->makeConsole("\n");
        $result = $console->prompt('Bir şey girin');
        $this->assertSame('', $result);
    }

    public function testConfirmTrueOnE(): void
    {
        [$console] = $this->makeConsole("e\n");
        $this->assertTrue($console->confirm('Devam?'));
    }

    public function testConfirmTrueOnEvet(): void
    {
        [$console] = $this->makeConsole("evet\n");
        $this->assertTrue($console->confirm('Devam?'));
    }

    public function testConfirmTrueOnY(): void
    {
        [$console] = $this->makeConsole("y\n");
        $this->assertTrue($console->confirm('Devam?'));
    }

    public function testConfirmFalseOnH(): void
    {
        [$console] = $this->makeConsole("h\n");
        $this->assertFalse($console->confirm('Devam?'));
    }

    public function testConfirmReturnsDefaultTrueOnEmptyInput(): void
    {
        [$console] = $this->makeConsole("\n");
        $this->assertTrue($console->confirm('Devam?', true));
    }

    public function testConfirmReturnsDefaultFalseOnEmptyInput(): void
    {
        [$console] = $this->makeConsole("\n");
        $this->assertFalse($console->confirm('Devam?', false));
    }

    public function testSuccessWritesToStdout(): void
    {
        [$console, $stdout] = $this->makeConsole('');
        $console->success('işlem tamam');
        $output = $this->readOutput($stdout);
        $this->assertStringContainsString('işlem tamam', $output);
    }

    public function testErrorWritesToStdout(): void
    {
        [$console, $stdout] = $this->makeConsole('');
        $console->error('bir hata oluştu');
        $output = $this->readOutput($stdout);
        $this->assertStringContainsString('bir hata oluştu', $output);
    }
}
