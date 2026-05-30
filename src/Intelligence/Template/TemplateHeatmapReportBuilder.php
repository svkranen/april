<?php

namespace App\Intelligence\Template;

use DateTimeImmutable;

final class TemplateHeatmapReportBuilder
{
    public function __construct(
        private readonly TemplateFlowHeatmapBuilder $flowHeatmapBuilder,
        private readonly TemplateDurationHeatmapBuilder $durationHeatmapBuilder
    ) {
    }

    /**
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $documentTimelines
     * @return array<string, mixed>
     */
    public function build(
        array $template,
        array $documentTimelines,
        ?DateTimeImmutable $now = null,
        bool $collapseDirectRepeats = true
    ): array {
        $now ??= new DateTimeImmutable();

        return [
            'template_key' => (string) ($template['key'] ?? ''),
            'documents_used' => $this->documentsUsed($documentTimelines),
            'generated_at' => $now->format(DATE_ATOM),
            'flow_heatmap' => $this->flowHeatmapBuilder->build($template, $documentTimelines, $collapseDirectRepeats),
            'duration_heatmap' => $this->durationHeatmapBuilder->build($template, $documentTimelines, $now, $collapseDirectRepeats),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $documentTimelines
     */
    private function documentsUsed(array $documentTimelines): int
    {
        $documentsUsed = 0;
        foreach ($documentTimelines as $documentTimeline) {
            $timeline = $documentTimeline['timeline'] ?? [];
            if ($this->hasUsableTimelineEntry($timeline)) {
                ++$documentsUsed;
            }
        }

        return $documentsUsed;
    }

    private function hasUsableTimelineEntry(mixed $timeline): bool
    {
        if (!is_array($timeline)) {
            return false;
        }

        foreach ($timeline as $entry) {
            if (is_array($entry) && isset($entry['step'], $entry['occurred_at'])) {
                return true;
            }
        }

        return false;
    }
}
