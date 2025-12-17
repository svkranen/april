<?php

namespace App\Service\Export;

use App\Dto\RenderedBlock;
use App\Dto\SyncOptions;
use Psr\Log\LoggerInterface;
use RuntimeException;

class AmagnoExporter implements ExporterInterface
{
    public function __construct(
        private readonly ExcelConverter $excelConverter,
        private readonly AmagnoUploader $uploader,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(string $target): bool
    {
        return $target === 'amagno';
    }

    /**
     * @param RenderedBlock[] $blocks
     */
    public function export(array $blocks, SyncOptions $options, string $templateName): void
    {
        if ($options->vaultId === null) {
            throw new RuntimeException('VaultId fehlt für Amagno Export.');
        }
        if ($options->token === null) {
            throw new RuntimeException('API Token fehlt für Amagno Export.');
        }

        foreach ($blocks as $index => $block) {
            $filename = $block->presetFilename ?? sprintf('%s_%s_%s', date('YmdHis'), $index, $templateName);
            $temp = tempnam(sys_get_temp_dir(), 'amagno_upload');
            $uploadName = $filename;
            if ($block->asExcel) {
                $uploadName = preg_replace('/\.[^.]+$/', '', $uploadName).'.xlsx';
                $tempUpload = $temp.'.xlsx';
                $this->excelConverter->write($block->content, $tempUpload);
                $this->logger->info('Upload als Excel', ['filename' => $uploadName]);
                $this->uploader->upload($options->token, $options->vaultId, $uploadName, $tempUpload);
                unlink($tempUpload);
            } else {
                file_put_contents($temp, $block->content);
                $this->uploader->upload($options->token, $options->vaultId, $uploadName, $temp);
            }
            unlink($temp);
        }
    }
}
