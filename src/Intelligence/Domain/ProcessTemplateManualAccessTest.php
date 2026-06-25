<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateManualAccessTest
{
    /**
     * @param array<int, string> $testProcedure
     * @param array<int, string> $expectedResult
     */
    public function __construct(
        public string $key,
        public ?string $title = null,
        public ?string $description = null,
        public array $testProcedure = [],
        public array $expectedResult = [],
        public ?string $frequency = null,
        public ?string $evidenceRequired = null
    ) {
    }
}
