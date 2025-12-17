<?php

namespace App\Service\Export;

use App\Dto\RenderedBlock;
use App\Dto\SyncOptions;
use Psr\Log\LoggerInterface;
use RuntimeException;

class FtpExporter implements ExporterInterface
{
    public function __construct(
        private readonly ExcelConverter $excelConverter,
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(string $target): bool
    {
        return $target === 'ftp';
    }

    /**
     * @param RenderedBlock[] $blocks
     */
    public function export(array $blocks, SyncOptions $options, string $templateName): void
    {
        foreach (['ftpServer','ftpUser','ftpPassword','ftpFolder'] as $property) {
            if ($options->{$property} === null) {
                throw new RuntimeException(sprintf('FTP Option "%s" fehlt.', $property));
            }
        }

        $this->logger->info('Verbinde mit FTP', ['server' => $options->ftpServer]);
        $conn = @ftp_connect($options->ftpServer);
        if (!$conn || !@ftp_login($conn, $options->ftpUser, $options->ftpPassword)) {
            $this->logger->error('FTP Verbindung fehlgeschlagen');
            throw new RuntimeException('FTP Verbindung fehlgeschlagen.');
        }

        foreach ($blocks as $index => $block) {
            $filename = $block->presetFilename ?? sprintf('%s_%s_%s', date('YmdHis'), $index, $templateName);
            $remote = rtrim($options->ftpFolder, '/').'/'.$filename;
            $temp = tempnam(sys_get_temp_dir(), 'amagno');
            $uploadPath = $temp;
            if ($block->asExcel) {
                $tempTarget = $temp.'.xlsx';
                $this->excelConverter->write($block->content, $tempTarget);
                $remote = preg_replace('/\.[^.]+$/', '', $remote).'.xlsx';
                $uploadPath = $tempTarget;
            } else {
                file_put_contents($temp, $block->content);
            }
            $this->logger->info('Lade Datei auf FTP hoch', ['remote' => $remote]);
            if (!ftp_put($conn, $remote, $uploadPath, FTP_BINARY)) {
                $this->logger->error('FTP Upload fehlgeschlagen', ['remote' => $remote]);
                unlink($uploadPath);
                unlink($temp);
                throw new RuntimeException(sprintf('FTP Upload fehlgeschlagen: %s', $remote));
            }
            unlink($uploadPath);
            if ($uploadPath !== $temp) {
                unlink($temp);
            }
        }

        ftp_close($conn);
    }
}
