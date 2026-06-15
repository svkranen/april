<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateAccessProbe;
use App\Intelligence\Domain\ProcessTemplateVisibilityProfile;
use App\Intelligence\Domain\ProcessTemplateVisibilityProfileResolver;

/**
 * Read model for the access/visibility coverage page. Bundles the static
 * AccessCoverageReport (checks, summary, manual tests) with the probe, profile
 * and resolver definitions so the Twig view never traverses domain objects.
 *
 * Purely descriptive: no access checks are executed here.
 */
final readonly class TemplateAccessView
{
    /**
     * @param array<string, int> $summary
     * @param array<int, array<string, mixed>> $checks
     * @param array<int, array<string, mixed>> $manualTests
     * @param array<int, array{key: string, sourceSystem: string, type: string, description: ?string, maxDocuments: ?int, pageSize: ?int, probeRef: ?string}> $probes
     * @param array<int, array{key: string, expectedVisibleInProbes: array<int, string>, expectedNotVisibleInProbes: array<int, string>}> $profiles
     * @param array<int, array{key: string, field: string, map: array<string, string>}> $resolvers
     */
    public function __construct(
        public string $key,
        public string $version,
        public string $sourceSystem,
        public array $summary,
        public array $checks,
        public array $manualTests,
        public array $probes,
        public array $profiles,
        public array $resolvers
    ) {
    }

    public static function fromTemplate(ProcessTemplate $template, AccessCoverageReport $report): self
    {
        $probes = array_map(
            static function (ProcessTemplateAccessProbe $probe): array {
                $options = $probe->options;

                return [
                    'key' => $probe->key,
                    'sourceSystem' => $probe->sourceSystem,
                    'type' => $probe->type,
                    'description' => $probe->description,
                    'maxDocuments' => $probe->maxDocuments,
                    'pageSize' => isset($options['page_size']) && is_numeric($options['page_size'])
                        ? (int) $options['page_size']
                        : null,
                    'probeRef' => self::scalarOrNull($options['magnet_id'] ?? $options['probe_ref'] ?? null),
                ];
            },
            array_values($template->accessProbes)
        );

        $profiles = array_map(
            static fn (ProcessTemplateVisibilityProfile $profile): array => [
                'key' => $profile->key,
                'expectedVisibleInProbes' => array_values($profile->expectedVisibleInProbeKeys),
                'expectedNotVisibleInProbes' => array_values($profile->expectedNotVisibleInProbeKeys),
            ],
            array_values($template->visibilityProfiles)
        );

        $resolvers = array_map(
            static fn (ProcessTemplateVisibilityProfileResolver $resolver): array => [
                'key' => $resolver->key,
                'field' => $resolver->field,
                'map' => $resolver->map,
            ],
            array_values($template->visibilityProfileResolvers)
        );

        return new self(
            $template->key,
            $template->version,
            $template->sourceSystem,
            $report->summary,
            $report->checks,
            $report->manualTests,
            $probes,
            $profiles,
            $resolvers
        );
    }

    private static function scalarOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
