<?php

namespace App\Intelligence\Application;

final readonly class JourneyDocumentCheckRow
{
    public function __construct(
        public ProcessDocumentRef $documentRef,
        public ?JourneyTemplateCheckResult $result = null,
        public ?string $error = null
    ) {
    }

    public function status(): string
    {
        if ($this->error !== null) {
            return 'ERROR';
        }

        return $this->result?->status ?? JourneyTemplateCheckService::STATUS_NOT_APPLICABLE;
    }
}
