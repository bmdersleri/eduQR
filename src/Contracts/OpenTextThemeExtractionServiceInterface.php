<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface OpenTextThemeExtractionServiceInterface
{
    /**
     * @param array<int,array<string,mixed>> $answers
     * @return array<int,array<string,mixed>>
     */
    public function extractThemes(string $questionText, array $answers, string $language): array;
}
