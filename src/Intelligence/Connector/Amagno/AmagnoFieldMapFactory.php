<?php

namespace App\Intelligence\Connector\Amagno;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateFieldMapping;

final class AmagnoFieldMapFactory
{
    /**
     * @return array<string, string>
     */
    public function fromTemplate(ProcessTemplate $template): array
    {
        $fieldMap = [];

        foreach ($template->fieldMappings as $mapping) {
            if (!$mapping instanceof ProcessTemplateFieldMapping || strtolower($mapping->source) !== 'amagno') {
                continue;
            }

            $tagReference = $mapping->tagId ?? $mapping->tagName;
            if ($tagReference === null || $tagReference === '') {
                continue;
            }

            $fieldMap[$mapping->fieldKey] = $tagReference;
        }

        return $fieldMap;
    }
}
