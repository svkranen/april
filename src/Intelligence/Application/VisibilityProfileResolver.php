<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateVisibilityCheck;

final class VisibilityProfileResolver
{
    /**
     * @param array<string, mixed> $context
     */
    public function resolve(ProcessTemplate $template, ProcessTemplateVisibilityCheck $check, array $context): VisibilityProfileResolutionResult
    {
        if ($check->expectedProfileKey !== null) {
            return isset($template->visibilityProfiles[$check->expectedProfileKey])
                ? new VisibilityProfileResolutionResult($check->expectedProfileKey)
                : new VisibilityProfileResolutionResult(null, 'unknown_profile');
        }

        if ($check->expectedProfileResolverKey === null) {
            return new VisibilityProfileResolutionResult(null, 'missing_profile_or_resolver');
        }

        $resolver = $template->visibilityProfileResolvers[$check->expectedProfileResolverKey] ?? null;
        if ($resolver === null) {
            return new VisibilityProfileResolutionResult(null, 'unknown_profile_resolver');
        }

        if (!array_key_exists($resolver->field, $context)) {
            return new VisibilityProfileResolutionResult(null, 'missing_context_field');
        }

        $value = $context[$resolver->field];
        if (!is_scalar($value)) {
            return new VisibilityProfileResolutionResult(null, 'unmapped_context_value');
        }

        $profileKey = $resolver->map[trim((string) $value)] ?? null;
        if ($profileKey === null || !isset($template->visibilityProfiles[$profileKey])) {
            return new VisibilityProfileResolutionResult(null, 'unmapped_context_value');
        }

        return new VisibilityProfileResolutionResult($profileKey);
    }
}
