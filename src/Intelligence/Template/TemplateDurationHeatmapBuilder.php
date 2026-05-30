<?php

namespace App\Intelligence\Template;

use DateTimeImmutable;

final class TemplateDurationHeatmapBuilder
{
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
        $stepDurations = [];
        $openAges = [];

        foreach ($this->templateSteps($template) as $stepKey) {
            $stepDurations[$stepKey] = [];
            $openAges[$stepKey] = [];
        }

        foreach ($documentTimelines as $documentTimeline) {
            $timeline = $this->normalizedTimeline($documentTimeline['timeline'] ?? [], $collapseDirectRepeats);
            if ($timeline === []) {
                continue;
            }

            for ($i = 0, $max = count($timeline) - 1; $i < $max; ++$i) {
                $stepKey = $timeline[$i]['step'];
                $stepDurations[$stepKey] ??= [];
                $openAges[$stepKey] ??= [];
                $stepDurations[$stepKey][] = $this->durationMinutes($timeline[$i]['occurred_at'], $timeline[$i + 1]['occurred_at']);
            }

            $lastEntry = $timeline[count($timeline) - 1];
            $stepKey = $lastEntry['step'];
            $stepDurations[$stepKey] ??= [];
            $openAges[$stepKey] ??= [];
            $openAges[$stepKey][] = $this->durationMinutes($lastEntry['occurred_at'], $now);
        }

        $maxAverageDuration = $this->maxAverage($stepDurations);
        $maxAverageOpenAge = $this->maxAverage($openAges);
        $maxOpenDocuments = $this->maxCount($openAges);
        $steps = [];

        foreach (array_keys($stepDurations + $openAges) as $stepKey) {
            $durations = $stepDurations[$stepKey] ?? [];
            $ages = $openAges[$stepKey] ?? [];
            $averageDuration = $this->average($durations);
            $averageOpenAge = $this->average($ages);
            $openDocuments = count($ages);

            $steps[] = [
                'step' => $stepKey,
                'historical' => [
                    'completed_documents' => count($durations),
                    'avg_duration_minutes' => $averageDuration,
                    'median_duration_minutes' => $this->median($durations),
                    'max_duration_minutes' => $durations === [] ? 0.0 : max($durations),
                ],
                'current' => [
                    'open_documents' => $openDocuments,
                    'avg_open_age_minutes' => $averageOpenAge,
                    'max_open_age_minutes' => $ages === [] ? 0.0 : max($ages),
                ],
                'intensity' => [
                    'historical_duration' => $maxAverageDuration <= 0.0 ? 0.0 : round($averageDuration / $maxAverageDuration, 4),
                    'current_backlog_age' => $maxAverageOpenAge <= 0.0 ? 0.0 : round($averageOpenAge / $maxAverageOpenAge, 4),
                    'current_backlog_count' => $maxOpenDocuments === 0 ? 0.0 : round($openDocuments / $maxOpenDocuments, 4),
                ],
            ];
        }

        usort(
            $steps,
            static fn (array $left, array $right): int => (max($right['intensity']) <=> max($left['intensity']))
                ?: ($right['current']['open_documents'] <=> $left['current']['open_documents'])
                ?: ($right['historical']['avg_duration_minutes'] <=> $left['historical']['avg_duration_minutes'])
                ?: ($left['step'] <=> $right['step'])
        );

        return [
            'steps' => $steps,
        ];
    }

    /**
     * @param array<int, float> $values
     */
    public function median(array $values): float
    {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $middle = intdiv($count, 2);
        if ($count % 2 === 1) {
            return (float) $values[$middle];
        }

        return ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    /**
     * @param array<string, mixed> $template
     * @return array<int, string>
     */
    private function templateSteps(array $template): array
    {
        $steps = $template['steps'] ?? [];
        if (!is_array($steps)) {
            return [];
        }

        $stepKeys = [];
        foreach ($steps as $step) {
            if (!is_array($step) || !isset($step['key'])) {
                continue;
            }

            $stepKeys[] = (string) $step['key'];
        }

        return $stepKeys;
    }

    /**
     * @param mixed $timeline
     * @return array<int, array{step: string, occurred_at: DateTimeImmutable}>
     */
    private function normalizedTimeline(mixed $timeline, bool $collapseDirectRepeats): array
    {
        if (!is_array($timeline)) {
            return [];
        }

        $normalized = [];
        foreach ($timeline as $entry) {
            if (!is_array($entry) || !isset($entry['step'], $entry['occurred_at'])) {
                continue;
            }

            $normalized[] = [
                'step' => (string) $entry['step'],
                'occurred_at' => $entry['occurred_at'] instanceof DateTimeImmutable
                    ? $entry['occurred_at']
                    : new DateTimeImmutable((string) $entry['occurred_at']),
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => $left['occurred_at'] <=> $right['occurred_at']
        );

        if (!$collapseDirectRepeats) {
            return $normalized;
        }

        $collapsed = [];
        $previousStep = null;
        foreach ($normalized as $entry) {
            if ($entry['step'] === $previousStep) {
                continue;
            }

            $collapsed[] = $entry;
            $previousStep = $entry['step'];
        }

        return $collapsed;
    }

    private function durationMinutes(DateTimeImmutable $from, DateTimeImmutable $to): float
    {
        return max(0.0, ($to->getTimestamp() - $from->getTimestamp()) / 60);
    }

    /**
     * @param array<int, float> $values
     */
    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 2);
    }

    /**
     * @param array<string, array<int, float>> $valuesByStep
     */
    private function maxAverage(array $valuesByStep): float
    {
        $max = 0.0;
        foreach ($valuesByStep as $values) {
            $max = max($max, $this->average($values));
        }

        return $max;
    }

    /**
     * @param array<string, array<int, float>> $valuesByStep
     */
    private function maxCount(array $valuesByStep): int
    {
        $max = 0;
        foreach ($valuesByStep as $values) {
            $max = max($max, count($values));
        }

        return $max;
    }
}
