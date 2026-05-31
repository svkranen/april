<?php

namespace App\Intelligence\Domain;

final readonly class ProcessTemplateContextPolicy
{
    public function __construct(
        public ?int $snapshotMaxDelaySeconds = null,
        public string $snapshotStaleBehavior = 'uncertain'
    ) {
    }
}
