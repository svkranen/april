<?php

namespace App\Tests\Export;

use App\Dto\RenderedBlock;
use App\Dto\SyncOptions;
use App\Service\Export\ExcelConverter;
use App\Service\Export\LocalExporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class LocalExporterTest extends TestCase
{
    public function testWritesPlainTextFile(): void
    {
        $dir = sys_get_temp_dir().'/amagno_local_'.uniqid();
        mkdir($dir);

        $converter = $this->createStub(ExcelConverter::class);
        $exporter = new LocalExporter($converter, new NullLogger());
        $options = $this->createOptions(folder: $dir);

        $block = new RenderedBlock('Hello World');
        $exporter->export([$block], $options, 'test.txt');

        $files = glob($dir.'/*');
        $this->assertCount(1, $files);
        $this->assertSame('Hello World', file_get_contents($files[0]));

        array_map('unlink', $files);
        rmdir($dir);
    }

    public function testConvertsExcelBlocks(): void
    {
        $dir = sys_get_temp_dir().'/amagno_local_excel_'.uniqid();
        mkdir($dir);

        $converter = new class extends ExcelConverter {
            public array $calls = [];
            public function write(string $content, string $destinationPath): void
            {
                $this->calls[] = [$content, $destinationPath];
                file_put_contents($destinationPath, 'excel');
            }
        };

        $exporter = new LocalExporter($converter, new NullLogger());
        $options = $this->createOptions(folder: $dir);

        $block = new RenderedBlock('1|2', 'custom.txt', true);
        $exporter->export([$block], $options, 'ignored.txt');

        $this->assertCount(1, $converter->calls);
        $files = glob($dir.'/*');
        $this->assertSame('excel', file_get_contents($files[0]));

        array_map('unlink', $files);
        rmdir($dir);
    }

    private function createOptions(?string $folder = null): SyncOptions
    {
        return new SyncOptions(
            magnetId: 'magnet',
            exportTarget: 'local',
            localFolder: $folder,
            batchSize: 1
        );
    }
}
