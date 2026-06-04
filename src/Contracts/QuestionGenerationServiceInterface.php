<?php

declare(strict_types=1);

namespace EduQR\Contracts;

interface QuestionGenerationServiceInterface
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function generateFromNotes(string $courseTitle, ?string $topicName, string $lectureNotes, string $language): array;
}
