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
final readonly class ProcessTemplateCatalog
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
