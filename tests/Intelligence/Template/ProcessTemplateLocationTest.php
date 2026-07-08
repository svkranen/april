<?php

namespace App\Tests\Intelligence\Template;

use App\Command\IntelligenceTemplateListCommand;
use App\Intelligence\Application\ProcessTemplateCatalog;
use App\Intelligence\Infrastructure\Template\YamlProcessTemplateProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Guards the directory split introduced when process templates moved out of the
 * Symfony/Twig templates/ directory:
 *   - APRIL process templates (YAML): config/april/process-templates
 *   - Twig/web views:                  templates/web
 */
class ProcessTemplateLocationTest extends TestCase
{
    private function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    private function processTemplateDir(): string
    {
        return $this->projectDir().'/config/april/process-templates';
    }

    private function twigWebDir(): string
    {
        return $this->projectDir().'/templates/web';
    }

    public function testProcessTemplateLivesUnderConfigAprilNotUnderTemplates(): void
    {
        self::assertFileExists($this->processTemplateDir().'/incident-management.yaml');
        self::assertFileDoesNotExist($this->projectDir().'/templates/incident-management.yaml');
    }

    public function testCatalogFindsIncidentManagementUnderConfigAprilOnly(): void
    {
        $result = (new ProcessTemplateCatalog($this->processTemplateDir()))->list();

        $keys = array_map(static fn ($entry): string => $entry->key, $result->entries);
        self::assertContains('incident-management', $keys);

        foreach ($result->entries as $entry) {
            self::assertStringContainsString('/config/april/process-templates/', $entry->path);
            self::assertStringEndsWith('.yaml', $entry->path);
            self::assertStringNotContainsString('/templates/web/', $entry->path);
            self::assertStringNotContainsString('.json', $entry->path);
        }
    }

    public function testCatalogDoesNotReadProcessTemplatesFromTwigDirectory(): void
    {
        $result = (new ProcessTemplateCatalog($this->twigWebDir()))->list();

        self::assertSame([], $result->entries);
        self::assertSame([], $result->warnings);
    }

    public function testProviderFindsIncidentManagementUnderConfigApril(): void
    {
        $provider = new YamlProcessTemplateProvider($this->processTemplateDir());

        $template = $provider->findByProcessKey('incident-management');
        self::assertNotNull($template);
        self::assertSame('incident-management', $template->key);
    }

    public function testProviderDoesNotResolveTemplatesFromTwigDirectory(): void
    {
        $provider = new YamlProcessTemplateProvider($this->twigWebDir());

        self::assertNull($provider->findByProcessKey('incident-management'));
    }

    public function testListCommandReportsIncidentManagementFromConfigApril(): void
    {
        $tester = new CommandTester(new IntelligenceTemplateListCommand($this->processTemplateDir()));
        $tester->execute(['--format' => 'json']);

        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        $keys = array_column($data['templates'], 'key');
        $paths = array_column($data['templates'], 'path');

        self::assertContains('incident-management', $keys);
        foreach ($paths as $path) {
            self::assertStringContainsString('/config/april/process-templates/', $path);
        }
    }

    public function testTwigViewsRemainUnderTemplatesWeb(): void
    {
        self::assertFileExists($this->twigWebDir().'/layout/base.html.twig');
        self::assertDirectoryExists($this->twigWebDir().'/template');
    }
}
