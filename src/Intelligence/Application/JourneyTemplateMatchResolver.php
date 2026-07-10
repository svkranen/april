<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;

final class JourneyTemplateMatchResolver
{
    public function resolve(ProcessTemplate $template): JourneyTemplateMatchKeys
    {
        if ($template->scope !== 'journey') {
            return new JourneyTemplateMatchKeys([], [
                sprintf('Template "%s" is not a journey template and cannot be matched as a journey.', $template->key),
            ]);
        }

        if ($template->match !== null && !$template->match->isEmpty()) {
            return new JourneyTemplateMatchKeys($template->match->anyProcessKeys);
        }

        foreach ($template->steps as $step) {
            if ($step->type !== 'process' || !$step->required || $step->processKey === null || trim($step->processKey) === '') {
                continue;
            }

            return new JourneyTemplateMatchKeys([trim($step->processKey)], [
                sprintf(
                    'Template "%s" has no explicit match.any_process; using first required process step "%s" as legacy fallback.',
                    $template->key,
                    trim($step->processKey)
                ),
            ]);
        }

        return new JourneyTemplateMatchKeys([], [
            sprintf(
                'Template "%s" has no explicit match.any_process and no required process step for legacy matching.',
                $template->key
            ),
        ]);
    }
}
