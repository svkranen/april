<?php

namespace App\Dto;

class RenderedBlock
{
    public function __construct(
        public string $content,
        public ?string $presetFilename = null,
        public bool $asExcel = false
    ) {
    }
}
