<?php

namespace App\Intelligence\Template;

use DateTimeImmutable;

final class TemplateFlowHeatmapBuilder
{
    /**
     * @param array<string, mixed> $template
     * @param array<int, array<string, mixed>> $documentTimelines
     * @return array<string, mixed>
     */
    public function build(array $template, array $documentTimelines, bool $collapseDirectRepeats = true): array
    {
        $allowedTransitions = $this->allowedTransitions($template);
        $transitionCounts = [];
        $documentsUsed = 0;

        foreach ($documentTimelines as $documentTimeline) {
            $timeline = $this->normalizedTimeline($documentTimeline['timeline'] ?? [], $collapseDirectRepeats);
            if ($timeline === []) {
                continue;
            }

            ++$documentsUsed;
            for ($i = 0, $max = count($timeline) - 1; $i < $max; ++$i) {
                $from = $timeline[$i]['step'];
                $to = $timeline[$i + 1]['step'];
                $key = $this->transitionKey($from, $to);

                if (!isset($transitionCounts[$key])) {
                    $transitionCounts[$key] = [
                        'from' => $from,
                        'to' => $to,
                        'count' => 0,
                    ];
                }

                ++$transitionCounts[$key]['count'];
            }
        }

        $maxCount = $this->maxCount($transitionCounts);
        $transitions = array_values(array_map(
            function (array $transition) use ($allowedTransitions, $documentsUsed, $maxCount): array {
                $count = $transition['count'];

                return [
                    'from' => $transition['from'],
                    'to' => $transition['to'],
                    'count' => $count,
                    'percentage' => $documentsUsed === 0 ? 0.0 : round($count / $documentsUsed * 100, 2),
                    'intensity' => $maxCount === 0 ? 0.0 : round($count / $maxCount, 4),
                    'is_allowed' => isset($allowedTransitions[$this->transitionKey($transition['from'], $transition['to'])]),
                ];
            },
            $transitionCounts
        ));

        usort(
            $transitions,
            static fn (array $left, array $right): int => ($right['count'] <=> $left['count'])
                ?: ($left['from'] <=> $right['from'])
                ?: ($left['to'] <=> $right['to'])
        );

        return [
            'transitions' => $transitions,
        ];
    }

    /**
     * @param array<string, mixed> $template
     * @return array<string, true>
     */
    private function allowedTransitions(array $template): array
    {
        $transitions = $template['allowed_transitions'] ?? $template['transitions'] ?? [];
        if (!is_array($transitions)) {
            return [];
        }

        $allowedTransitions = [];
        foreach ($transitions as $transition) {
            if (!is_array($transition) || !isset($transition['from'], $transition['to'])) {
                continue;
            }

            $allowedTransitions[$this->transitionKey((string) $transition['from'], (string) $transition['to'])] = true;
        }

        return $allowedTransitions;
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

    /**
     * @param array<string, array{from: string, to: string, count: int}> $transitionCounts
     */
    private function maxCount(array $transitionCounts): int
    {
        if ($transitionCounts === []) {
            return 0;
        }

        return max(array_map(
            static fn (array $transition): int => $transition['count'],
            $transitionCounts
        ));
    }

    private function transitionKey(string $from, string $to): string
    {
        return $from . "\0" . $to;
    }
}
