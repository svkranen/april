<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use InvalidArgumentException;

final class TemplateSuggestionScopeResolver
{
    public const SCOPE_PROCESS = 'process';
    public const SCOPE_JOURNEY = 'journey';

    public function resolve(?ProcessTemplate $template, ?string $scopeOverride = null): string
    {
        $scope = $scopeOverride === null || trim($scopeOverride) === ''
            ? ($template?->scope ?? self::SCOPE_PROCESS)
            : $scopeOverride;

        $scope = strtolower(trim($scope));
        if (!in_array($scope, [self::SCOPE_PROCESS, self::SCOPE_JOURNEY], true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported template scope "%s". Use one of: process, journey.',
                $scope
            ));
        }

        return $scope;
    }
}
