<?php

namespace App\Service\Export;

use App\Dto\SyncOptions;
use RuntimeException;

class ExporterRegistry
{
    /**
     * @param iterable<ExporterInterface> $exporters
     */
    public function __construct(
        private readonly iterable $exporters
    ) {
    }

    /**
     * @param array<int, string> $contents
     */
    public function export(string $target, array $contents, SyncOptions $options, string $templateName): void
    {
        foreach ($this->exporters as $exporter) {
            if ($exporter->supports($target)) {
                $exporter->export($contents, $options, $templateName);
                return;
            }
        }

        throw new RuntimeException(sprintf('Kein Exporter für Ziel "%s" registriert.', $target));
    }
}
