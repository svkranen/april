<?php

namespace App\Intelligence\Bpmn;

final readonly class BpmnSvgRenderOptions
{
    public function __construct(
        public string $view = 'combined',
        public int $minUnexpectedCount = 2,
        public int $width = 1200,
        public bool $compact = true
    ) {
    }
}
