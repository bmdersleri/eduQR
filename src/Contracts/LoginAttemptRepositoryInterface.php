<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface LoginAttemptRepositoryInterface
{
    public function record(string $email, ?string $ipHash, bool $succeeded): void;

    public function countRecentFailures(string $email, int $windowSeconds): int;
}
