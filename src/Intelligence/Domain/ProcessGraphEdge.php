<?php

namespace App\Intelligence\Domain;

final readonly class ProcessGraphEdge
{
    public const STYLE_FLOW = 'flow';
    public const STYLE_CONSTRAINT = 'constraint';
    public const STYLE_IMPLICIT = 'implicit';

    public function __construct(
        public string $from,
        public string $to,
        public ?string $label = null,
        public ?string $condition = null,
        public string $style = self::STYLE_FLOW
    ) {
    }
}
