<?php

namespace App\Dto;

class MatchingContext
{
    public function __construct(
        public readonly array $matching,
        public readonly string $templateContent,
        public readonly string $templateName
    ) {
    }
}
