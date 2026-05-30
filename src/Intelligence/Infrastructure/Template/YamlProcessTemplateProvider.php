<?php

namespace App\Intelligence\Infrastructure\Template;

use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use Symfony\Component\Yaml\Yaml;

final readonly class YamlProcessTemplateProvider implements ProcessTemplateProvider
{
    public function __construct(
        private string $templateDirectory
    ) {
    }

    public function findByProcessKey(string $processKey): ?ProcessTemplate
    {
        $processKey = trim($processKey);
        if ($processKey === '') {
            return null;
        }

        $path = rtrim($this->templateDirectory, '/').'/'.$processKey.'.yaml';
        if (!is_file($path)) {
            return null;
        }

        $data = Yaml::parseFile($path);
        if (!is_array($data)) {
            return null;
        }

        return ProcessTemplateArrayFactory::fromArray($data);
    }
}
