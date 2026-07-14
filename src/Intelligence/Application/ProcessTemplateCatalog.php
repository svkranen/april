<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Lists the YAML process templates available in the template directory.
 *
 * Single source of truth shared by the CLI (intelligence:template:list) and the
 * web frontend; invalid templates are reported as warnings instead of aborting.
 */
final readonly class ProcessTemplateCatalog implements ProcessTemplateProvider
{
    public function __construct(
        private string $templateDirectory
    ) {
    }

    public function list(): ProcessTemplateCatalogResult
    {
        $entries = [];
        $warnings = [];
        $paths = glob(rtrim($this->templateDirectory, '/').'/*.yaml') ?: [];
        sort($paths);

        foreach ($paths as $path) {
            try {
                $template = $this->loadTemplate($path);
                $entries[] = new ProcessTemplateCatalogEntry(
                    $template->key,
                    $template->version,
                    $template->name,
                    $path
                );
            } catch (Throwable $exception) {
                $warnings[] = [
                    'path' => $path,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return new ProcessTemplateCatalogResult($entries, $warnings);
    }

    public function findByProcessKey(string $processKey): ?ProcessTemplate
    {
        $entry = $this->findEntryByProcessKey($processKey);

        return $entry === null ? null : $this->loadTemplate($entry->path);
    }

    public function pathForProcessKey(string $processKey): ?string
    {
        return $this->findEntryByProcessKey($processKey)?->path;
    }

    private function findEntryByProcessKey(string $processKey): ?ProcessTemplateCatalogEntry
    {
        if (preg_match('/^[A-Za-z0-9._-]+$/D', $processKey) !== 1) {
            return null;
        }

        foreach ($this->list()->entries as $entry) {
            if ($entry->key === $processKey) {
                return $entry;
            }
        }

        return null;
    }

    private function loadTemplate(string $path): ProcessTemplate
    {
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new ParseException(sprintf('Invalid YAML: %s', $exception->getMessage()), 0, $exception);
        }

        if (!is_array($data)) {
            throw new RuntimeException('Template file is not a YAML mapping.');
        }

        $template = ProcessTemplateArrayFactory::fromArray($data);
        if ($template->key === '') {
            throw new RuntimeException('Template key is missing.');
        }

        return $template;
    }
}
