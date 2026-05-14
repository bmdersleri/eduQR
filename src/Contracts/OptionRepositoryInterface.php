<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface OptionRepositoryInterface
{
    /** @param array $options Each element: ['option_text', 'option_value', 'is_correct', 'order_no'] */
    public function createBulk(int $questionId, array $options): void;

    public function findByQuestion(int $questionId): array;

    public function deleteByQuestion(int $questionId): void;

    public function findById(int $id): ?array;
}
