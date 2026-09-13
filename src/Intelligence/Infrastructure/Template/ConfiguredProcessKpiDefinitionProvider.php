<?php

namespace App\Intelligence\Infrastructure\Template;

use App\Intelligence\Domain\KpiEventMarker;
use App\Intelligence\Domain\ProcessKpiDefinition;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Port\ProcessKpiDefinitionProvider;
use InvalidArgumentException;

final readonly class ConfiguredProcessKpiDefinitionProvider implements ProcessKpiDefinitionProvider
{
    /** @param array<string, array<string, mixed>> $definitions */
    public function __construct(private array $definitions = [])
    {
    }

    public function forTemplate(ProcessTemplate $template): ?ProcessKpiDefinition
    {
        $config = $this->definitions[$template->key] ?? null;
        if ($config === null || ($config['template_version'] ?? null) !== $template->version) {
            return null;
        }
        if (!is_string($config['version'] ?? null) || !is_array($config['completion_groups'] ?? null)) {
            throw new InvalidArgumentException('Invalid process KPI definition.');
        }
        $groups = [];
        foreach ($config['completion_groups'] as $group) {
            if (!is_array($group)) {
                throw new InvalidArgumentException('Invalid completion group.');
            }
            $groups[] = array_map($this->marker(...), array_values($group));
        }

        return ProcessKpiDefinition::forTemplate($template, $config['version'], $this->marker($config['start'] ?? null), $groups);
    }

    private function marker(mixed $marker): KpiEventMarker
    {
        if (!is_array($marker) || !is_string($marker['step'] ?? null) || !is_string($marker['phase'] ?? null)) {
            throw new InvalidArgumentException('Invalid KPI marker.');
        }

        return KpiEventMarker::at($marker['step'], $marker['phase']);
    }
}
