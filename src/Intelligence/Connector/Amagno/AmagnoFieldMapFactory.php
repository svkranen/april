<?php

namespace App\Intelligence\Connector\Amagno;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateFieldMapping;

final class AmagnoFieldMapFactory
{
    /**
     * @return array<string, AmagnoFieldMapping>
     */
    public function fromTemplate(ProcessTemplate $template): array
    {
        $fieldMap = [];

        foreach ($template->fieldMappings as $mapping) {
            if (!$mapping instanceof ProcessTemplateFieldMapping || strtolower($mapping->source) !== 'amagno') {
                continue;
            }

            if (($mapping->tagId === null || $mapping->tagId === '') && ($mapping->tagName === null || $mapping->tagName === '')) {
                continue;
            }

            $fieldMap[$mapping->fieldKey] = new AmagnoFieldMapping(
                $mapping->fieldKey,
                $mapping->tagId,
                $mapping->tagName,
                $mapping->valueType
            );
        }

        return $fieldMap;
    }
}
