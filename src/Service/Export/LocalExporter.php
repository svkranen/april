<?php

namespace App\Service\Export;

use App\Dto\RenderedBlock;
use App\Dto\SyncOptions;
use Psr\Log\LoggerInterface;
use RuntimeException;

class LocalExporter implements ExporterInterface
{
    public function __construct(
        private readonly ExcelConverter $excelConverter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(string $target): bool
    {
        return $target === 'local';
    }

    /**
     * @param RenderedBlock[] $blocks
     */
    public function export(array $blocks, SyncOptions $options, string $templateName): void
    {
        if ($options->localFolder === null) {
            throw new RuntimeException('Lokaler Ordner ist nicht definiert.');
        }

        $folder = rtrim($options->localFolder, DIRECTORY_SEPARATOR);
        if (!is_dir($folder)) {
            throw new RuntimeException(sprintf('Lokaler Ordner "%s" existiert nicht.', $folder));
        }

        foreach ($blocks as $index => $block) {
            $filename = $block->presetFilename ?? sprintf('%s_%s_%s', date('YmdHis'), $index, $templateName);
            $path = $folder.'/'.$filename;
            $this->logger->info('Schreibe lokale Exportdatei', ['path' => $path, 'excel' => $block->asExcel]);
            if ($block->asExcel) {
                $path = preg_replace('/\.[^.]+$/', '', $path).'.xlsx';
                $this->excelConverter->write($block->content, $path);
            } else {
                $bytes = file_put_contents($path, $block->content);
                if ($bytes === false) {
                    $this->logger->error('Konnte Datei nicht schreiben', ['path' => $path]);
                    throw new RuntimeException(sprintf('Datei "%s" konnte nicht geschrieben werden.', $path));
                }
            }
        }
    }
}
