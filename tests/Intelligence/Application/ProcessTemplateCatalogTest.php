<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ProcessTemplateCatalog;
use PHPUnit\Framework\TestCase;

class ProcessTemplateCatalogTest extends TestCase
{
    public function testFindsYamlTemplatesAndIgnoresOtherFiles(): void
    {
        $directory = $this->createDirectory([
            'invoice.yaml' => "key: invoice\nversion: '1'\nname: Rechnungseingang\nsteps: []\n",
            'order.yaml' => "key: order\nversion: draft\nsteps: []\n",
            'notes.txt' => 'not a template',
            'data.json' => '{"key":"json"}',
        ]);

        $result = (new ProcessTemplateCatalog($directory))->list();

        self::assertCount(2, $result->entries);
        self::assertSame([], $result->warnings);

        self::assertSame('invoice', $result->entries[0]->key);
        self::assertSame('1', $result->entries[0]->version);
        self::assertSame('Rechnungseingang', $result->entries[0]->name);
        self::assertSame($directory.'/invoice.yaml', $result->entries[0]->path);

        self::assertSame('order', $result->entries[1]->key);
        self::assertNull($result->entries[1]->name);

        $this->removeDirectory($directory);
    }

    public function testReportsWarningsInsteadOfAborting(): void
    {
        $directory = $this->createDirectory([
            'invoice.yaml' => "key: invoice\nversion: '1'\nsteps: []\n",
            'missing-key.yaml' => "version: '1'\nsteps: []\n",
            'broken.yaml' => "key: broken\n  : : invalid\n",
        ]);

        $result = (new ProcessTemplateCatalog($directory))->list();

        // Valid template still parsed despite siblings failing.
        self::assertCount(1, $result->entries);
        self::assertSame('invoice', $result->entries[0]->key);

        $messagesByFile = [];
        foreach ($result->warnings as $warning) {
            $messagesByFile[basename($warning['path'])] = $warning['message'];
        }

        self::assertArrayHasKey('missing-key.yaml', $messagesByFile);
        self::assertSame('Template key is missing.', $messagesByFile['missing-key.yaml']);

        self::assertArrayHasKey('broken.yaml', $messagesByFile);
        self::assertStringStartsWith('Invalid YAML:', $messagesByFile['broken.yaml']);

        $this->removeDirectory($directory);
    }

    public function testFindsRealAiRechnungenTemplate(): void
    {
        $directory = dirname(__DIR__, 3).'/config/april/process-templates';

        $result = (new ProcessTemplateCatalog($directory))->list();

        $keys = array_map(static fn ($entry): string => $entry->key, $result->entries);
        self::assertContains('ai-rechnungen', $keys);
    }

    /**
     * @param array<string, string> $files
     */
    private function createDirectory(array $files): string
    {
        $directory = sys_get_temp_dir().'/intelligence-catalog-'.bin2hex(random_bytes(4));
        mkdir($directory);
        foreach ($files as $name => $content) {
            file_put_contents($directory.'/'.$name, $content);
        }

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        foreach (glob($directory.'/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}
