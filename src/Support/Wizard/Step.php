<?php

declare(strict_types=1);

namespace EduQR\Support\Wizard;

/**
 * Abstract base for each wizard step.
 *
 * Implement title() for the section header shown in the wizard progress line.
 * Implement run() to perform the step logic; return true to continue, false to abort.
 */
abstract class Step
{
    abstract public function title(): string;

    /**
     * Execute the step interactively via the Console.
     *
     * @return bool true = continue to next step, false = abort wizard
     */
    abstract public function run(Console $console): bool;
}
