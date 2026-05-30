<?php

namespace App\Tests\Intelligence\Template;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;
use App\Intelligence\Template\TemplateDurationHeatmapBuilder;
use App\Intelligence\Template\TemplateFlowHeatmapBuilder;
use App\Intelligence\Template\TemplateHeatmapReportBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TemplateHeatmapBuilderTest extends TestCase
{
    public function testFlowHeatmapCountsTransitionsAndReverseOrderSeparately(): void
    {
        $heatmap = (new TemplateFlowHeatmapBuilder())->build($this->template(), [
            $this->timeline('doc-1', [
                ['03 Ausgangsrechnung buchen', '2026-05-29 10:00:00'],
                ['04 Zahlungseingang erwartet', '2026-05-29 11:00:00'],
            ]),
            $this->timeline('doc-2', [
                ['04 Zahlungseingang erwartet', '2026-05-29 10:00:00'],
                ['03 Ausgangsrechnung buchen', '2026-05-29 11:00:00'],
            ]),
            $this->timeline('doc-3', [
                ['03 Ausgangsrechnung buchen', '2026-05-29 12:00:00'],
                ['04 Zahlungseingang erwartet', '2026-05-29 13:00:00'],
            ]),
        ]);

        $forward = $this->transition($heatmap, '03 Ausgangsrechnung buchen', '04 Zahlungseingang erwartet');
        $reverse = $this->transition($heatmap, '04 Zahlungseingang erwartet', '03 Ausgangsrechnung buchen');

        self::assertSame(2, $forward['count']);
        self::assertSame(66.67, $forward['percentage']);
        self::assertSame(1.0, $forward['intensity']);
        self::assertTrue($forward['is_allowed']);
        self::assertSame(1, $reverse['count']);
        self::assertSame(33.33, $reverse['percentage']);
        self::assertSame(0.5, $reverse['intensity']);
        self::assertTrue($reverse['is_allowed']);
    }

    public function testFlowHeatmapDetectsNotAllowedTransitions(): void
    {
        $heatmap = (new TemplateFlowHeatmapBuilder())->build($this->template(), [
            $this->timeline('doc-1', [
                ['01 Ausgangsrechnung pruefen', '2026-05-29 10:00:00'],
                ['04 Zahlungseingang erwartet', '2026-05-29 11:00:00'],
            ]),
        ]);

        $transition = $this->transition($heatmap, '01 Ausgangsrechnung pruefen', '04 Zahlungseingang erwartet');

        self::assertSame(1, $transition['count']);
        self::assertFalse($transition['is_allowed']);
    }

    public function testDurationHeatmapCalculatesCompletedDurations(): void
    {
        $heatmap = (new TemplateDurationHeatmapBuilder())->build($this->template(), [
            $this->timeline('doc-1', [
                ['02 Versenden', '2026-05-29 10:00:00'],
                ['03 Ausgangsrechnung buchen', '2026-05-29 12:00:00'],
            ]),
            $this->timeline('doc-2', [
                ['02 Versenden', '2026-05-29 10:00:00'],
                ['03 Ausgangsrechnung buchen', '2026-05-29 11:00:00'],
            ]),
            $this->timeline('doc-3', [
                ['02 Versenden', '2026-05-29 10:00:00'],
                ['03 Ausgangsrechnung buchen', '2026-05-29 13:00:00'],
            ]),
        ], new DateTimeImmutable('2026-05-30 10:00:00'));

        $step = $this->step($heatmap, '02 Versenden');

        self::assertSame(3, $step['historical']['completed_documents']);
        self::assertSame(120.0, $step['historical']['avg_duration_minutes']);
        self::assertSame(120.0, $step['historical']['median_duration_minutes']);
        self::assertSame(180.0, $step['historical']['max_duration_minutes']);
    }

    public function testDurationHeatmapCalculatesOpenDurationUntilNow(): void
    {
        $heatmap = (new TemplateDurationHeatmapBuilder())->build($this->template(), [
            $this->timeline('doc-1', [
                ['04 Zahlungseingang erwartet', '2026-05-29 10:00:00'],
            ]),
            $this->timeline('doc-2', [
                ['04 Zahlungseingang erwartet', '2026-05-29 12:00:00'],
            ]),
        ], new DateTimeImmutable('2026-05-29 14:00:00'));

        $step = $this->step($heatmap, '04 Zahlungseingang erwartet');

        self::assertSame(2, $step['current']['open_documents']);
        self::assertSame(180.0, $step['current']['avg_open_age_minutes']);
        self::assertSame(240.0, $step['current']['max_open_age_minutes']);
        self::assertSame(1.0, $step['intensity']['current_backlog_count']);
    }

    public function testMedianCalculationHandlesOddAndEvenCounts(): void
    {
        $builder = new TemplateDurationHeatmapBuilder();

        self::assertSame(90.0, $builder->median([30.0, 90.0, 180.0]));
        self::assertSame(60.0, $builder->median([30.0, 90.0]));
    }

    public function testUnknownStepsRemainVisibleInFlowAndDurationHeatmaps(): void
    {
        $documentTimelines = [
            $this->timeline('doc-1', [
                ['02 Versenden', '2026-05-29 10:00:00'],
                ['Sonderklaerung', '2026-05-29 11:00:00'],
            ]),
        ];
        $report = (new TemplateHeatmapReportBuilder(
            new TemplateFlowHeatmapBuilder(),
            new TemplateDurationHeatmapBuilder()
        ))->build($this->template(), $documentTimelines, new DateTimeImmutable('2026-05-29 12:00:00'));

        $transition = $this->transition($report['flow_heatmap'], '02 Versenden', 'Sonderklaerung');
        $step = $this->step($report['duration_heatmap'], 'Sonderklaerung');

        self::assertFalse($transition['is_allowed']);
        self::assertSame(1, $step['current']['open_documents']);
        self::assertSame(60.0, $step['current']['avg_open_age_minutes']);
    }

    public function testDirectRepeatedStepsAreCollapsedByDefault(): void
    {
        $heatmap = (new TemplateFlowHeatmapBuilder())->build($this->template(), [
            $this->timeline('doc-1', [
                ['02 Versenden', '2026-05-29 10:00:00'],
                ['02 Versenden', '2026-05-29 10:01:00'],
                ['03 Ausgangsrechnung buchen', '2026-05-29 10:05:00'],
            ]),
        ]);

        self::assertNull($this->transitionOrNull($heatmap, '02 Versenden', '02 Versenden'));
        self::assertSame(1, $this->transition($heatmap, '02 Versenden', '03 Ausgangsrechnung buchen')['count']);
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(
            'ai-ausgangsrechnungen',
            steps: [
                new ProcessTemplateStep('01 Ausgangsrechnung pruefen'),
                new ProcessTemplateStep('02 Versenden'),
                new ProcessTemplateStep('03 Ausgangsrechnung buchen'),
                new ProcessTemplateStep('04 Zahlungseingang erwartet'),
            ],
            transitions: [
                new ProcessTemplateTransition('01 Ausgangsrechnung pruefen', '02 Versenden'),
                new ProcessTemplateTransition('02 Versenden', '03 Ausgangsrechnung buchen'),
                new ProcessTemplateTransition('02 Versenden', '04 Zahlungseingang erwartet'),
                new ProcessTemplateTransition('03 Ausgangsrechnung buchen', '04 Zahlungseingang erwartet'),
                new ProcessTemplateTransition('04 Zahlungseingang erwartet', '03 Ausgangsrechnung buchen'),
            ],
        );
    }

    /**
     * @param array<int, array{0: string, 1: string}> $entries
     * @return array{document_uuid: string, timeline: array<int, array{step: string, occurred_at: string}>}
     */
    private function timeline(string $documentUuid, array $entries): array
    {
        return [
            'document_uuid' => $documentUuid,
            'timeline' => array_map(
                static fn (array $entry): array => [
                    'step' => $entry[0],
                    'occurred_at' => $entry[1],
                ],
                $entries
            ),
        ];
    }

    /**
     * @param array<string, mixed> $heatmap
     * @return array<string, mixed>
     */
    private function transition(array $heatmap, string $from, string $to): array
    {
        $transition = $this->transitionOrNull($heatmap, $from, $to);
        self::assertNotNull($transition);

        return $transition;
    }

    /**
     * @param array<string, mixed> $heatmap
     * @return array<string, mixed>|null
     */
    private function transitionOrNull(array $heatmap, string $from, string $to): ?array
    {
        foreach ($heatmap['transitions'] as $transition) {
            if ($transition['from'] === $from && $transition['to'] === $to) {
                return $transition;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $heatmap
     * @return array<string, mixed>
     */
    private function step(array $heatmap, string $stepKey): array
    {
        foreach ($heatmap['steps'] as $step) {
            if ($step['step'] === $stepKey) {
                return $step;
            }
        }

        self::fail(sprintf('Step "%s" was not found.', $stepKey));
    }
}
