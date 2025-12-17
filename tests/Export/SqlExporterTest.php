<?php

namespace App\Tests\Export;

use App\Dto\RenderedBlock;
use App\Dto\SyncOptions;
use App\Service\Export\SqlExporter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

class SqlExporterTest extends TestCase
{
    public function testThrowsWhenConfigurationMissing(): void
    {
        $exporter = new SqlExporter(new NullLogger());
        $options = new SyncOptions('magnet', 'sql');

        $this->expectException(RuntimeException::class);
        $exporter->export([new RenderedBlock('INSERT ...')], $options, 'template.txt');
    }

    public function testRejectsExcelBlocks(): void
    {
        $exporter = new SqlExporter(new NullLogger());
        $options = new SyncOptions(
            magnetId: 'magnet',
            exportTarget: 'sql',
            dbHost: 'localhost',
            dbName: 'db',
            dbUser: 'user',
            dbPassword: 'pw'
        );

        $this->expectException(RuntimeException::class);
        $exporter->export([new RenderedBlock('data', null, true)], $options, 'template.txt');
    }
}
