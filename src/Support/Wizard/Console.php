<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard;

/**
 * ANSI-colored CLI I/O helper for the setup wizard.
 *
 * $stdin / $stdout are injectable for unit testing:
 *   $stdin  = fopen('php://memory', 'r+');
 *   $stdout = fopen('php://memory', 'r+');
 *   $console = new Console($stdin, $stdout);
 */
class Console
{
    private const RESET  = "\033[0m";
    private const GREEN  = "\033[32m";
    private const RED    = "\033[31m";
    private const YELLOW = "\033[33m";
    private const CYAN   = "\033[36m";
    private const BOLD   = "\033[1m";

    /** @param resource $stdin @param resource $stdout */
    public function __construct(
        private mixed $stdin  = STDIN,
        private mixed $stdout = STDOUT,
    ) {}

    public function writeln(string $text = ''): void
    {
        fwrite($this->stdout, $text . "\n");
    }

    public function success(string $text): void
    {
        fwrite($this->stdout, self::GREEN . "  ✓ " . self::RESET . $text . "\n");
    }

    public function error(string $text): void
    {
        fwrite($this->stdout, self::RED . "  ✗ " . self::RESET . $text . "\n");
    }

    public function warn(string $text): void
    {
        fwrite($this->stdout, self::YELLOW . "  ! " . self::RESET . $text . "\n");
    }

    public function info(string $text): void
    {
        fwrite($this->stdout, self::CYAN . "  → " . self::RESET . $text . "\n");
    }

    public function banner(string $title): void
    {
        $width = 48;
        $line  = str_repeat('═', $width);
        $pad   = str_pad($title, $width, ' ', STR_PAD_BOTH);
        $this->writeln();
        $this->writeln(self::BOLD . "╔{$line}╗" . self::RESET);
        $this->writeln(self::BOLD . "║{$pad}║" . self::RESET);
        $this->writeln(self::BOLD . "╚{$line}╝" . self::RESET);
        $this->writeln();
    }

    public function section(int $step, int $total, string $title): void
    {
        $this->writeln();
        $this->writeln(self::BOLD . "[{$step}/{$total}] {$title}" . self::RESET);
    }

    /**
     * Prompt the user for text input.
     * Returns $default on empty input or EOF.
     */
    public function prompt(string $question, string $default = ''): string
    {
        $hint = $default !== '' ? " [{$default}]" : '';
        fwrite($this->stdout, "  {$question}{$hint}: ");
        $line = fgets($this->stdin);
        if ($line === false) {
            return $default;
        }
        $input = trim($line);
        return $input !== '' ? $input : $default;
    }

    /**
     * Prompt for a password (disables terminal echo on Unix/Linux).
     * Falls back to plain fgets when stdin is not the real terminal (e.g. tests).
     */
    public function secret(string $question): string
    {
        fwrite($this->stdout, "  {$question}: ");
        $isRealTty = ($this->stdin === STDIN && PHP_OS_FAMILY !== 'Windows');
        if ($isRealTty) {
            system('stty -echo');
        }
        $line = fgets($this->stdin);
        if ($isRealTty) {
            system('stty echo');
            fwrite($this->stdout, "\n");
        }
        return $line !== false ? trim($line) : '';
    }

    /**
     * Ask a yes/no question.
     * Accepts: e / evet / y / yes / 1  → true
     *          h / hayır / n / no  / 0 → false
     * Returns $default on empty input or EOF.
     */
    public function confirm(string $question, bool $default = true): bool
    {
        $hint = $default ? 'E/h' : 'e/H';
        fwrite($this->stdout, "  {$question} [{$hint}]: ");
        $line = fgets($this->stdin);
        if ($line === false) {
            return $default;
        }
        $input = strtolower(trim($line));
        if ($input === '') {
            return $default;
        }
        return in_array($input, ['e', 'evet', 'y', 'yes', '1'], true);
    }
}
