<?php

namespace App\Service\Export;

use App\Dto\RenderedBlock;
use App\Dto\SyncOptions;

interface ExporterInterface
{
    public function supports(string $target): bool;

    /**
     * @param RenderedBlock[] $blocks
     */
    public function export(array $blocks, SyncOptions $options, string $templateName): void;
}
