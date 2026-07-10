<?php

namespace App\Tests\Intelligence\Infrastructure\Template;

use App\Intelligence\Infrastructure\Template\YamlJourneyMatchWriter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class YamlJourneyMatchWriterTest extends TestCase
{
    private string $templateDirectory;

    protected function setUp(): void
    {
        $this->templateDirectory = sys_get_temp_dir().'/april-match-writer-'.uniqid('', true);
        mkdir($this->templateDirectory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->templateDirectory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->templateDirectory);
    }

    public function testWritesMatchAndPreservesOtherFields(): void
    {
        $this->writeTemplate('my-journey', $this->journeyYaml());

        $this->writer()->saveMatch('my-journey', ['intake_process', 'export_process']);

        $data = Yaml::parseFile($this->templateDirectory.'/my-journey.yaml');
        self::assertSame(['intake_process', 'export_process'], $data['match']['any_process']);
        self::assertSame('my-journey', $data['key']);
        self::assertSame('journey', $data['scope']);
        self::assertSame('1.0', (string) $data['version']);
        self::assertCount(1, $data['steps']);
        self::assertSame('intake', $data['steps'][0]['key']);
        $this->assertNoLeftoverTemporaryFiles();
    }

    public function testEmptySelectionRemovesExplicitMatch(): void
    {
        $this->writeTemplate('my-journey', $this->journeyYaml());

        $this->writer()->saveMatch('my-journey', []);

        $data = Yaml::parseFile($this->templateDirectory.'/my-journey.yaml');
        self::assertArrayNotHasKey('match', $data);
        self::assertSame('journey', $data['scope']);
    }

    public function testRejectsNonJourneyTemplate(): void
    {
        $this->writeTemplate('plain-process', "key: plain-process\nversion: 1.0\nscope: process\nsteps: []\n");

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('can only be edited on journey templates');

        $this->writer()->saveMatch('plain-process', ['intake_process']);
    }

    public function testRejectsMissingTemplateFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->writer()->saveMatch('does-not-exist', ['intake_process']);
    }

    public function testInvalidResultingTemplateIsNotWritten(): void
    {
        // Transition references an unknown step - the file is already invalid,
        // and the pre-write factory validation must refuse to touch it.
        $invalidYaml = <<<'YAML'
key: broken-journey
version: 1.0
scope: journey
steps:
  - key: intake
    type: process
    process_key: intake_process
transitions:
  - from: ghost
    to: intake
YAML;
        $this->writeTemplate('broken-journey', $invalidYaml);

        try {
            $this->writer()->saveMatch('broken-journey', ['intake_process']);
            self::fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('ghost', $exception->getMessage());
        }

        self::assertSame($invalidYaml, file_get_contents($this->templateDirectory.'/broken-journey.yaml'));
        $this->assertNoLeftoverTemporaryFiles();
    }

    private function assertNoLeftoverTemporaryFiles(): void
    {
        self::assertSame(
            [],
            glob($this->templateDirectory.'/*.tmp.*') ?: [],
            'The atomic write must not leave temporary files behind.'
        );
    }

    private function writer(): YamlJourneyMatchWriter
    {
        return new YamlJourneyMatchWriter($this->templateDirectory);
    }

    private function writeTemplate(string $key, string $yaml): void
    {
        file_put_contents($this->templateDirectory.'/'.$key.'.yaml', $yaml);
    }

    private function journeyYaml(): string
    {
        return <<<'YAML'
key: my-journey
version: "1.0"
scope: journey
match:
  any_process:
    - old_process
steps:
  - key: intake
    type: process
    process_key: intake_process
    required: true
YAML;
    }
}
