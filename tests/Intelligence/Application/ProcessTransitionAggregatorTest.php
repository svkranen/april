<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ProcessTransitionAggregator;
use App\Intelligence\Domain\KpiEventMarker;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessKpiDefinition;
use App\Intelligence\Domain\ProcessRunReconstructor;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessVersion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ProcessTransitionAggregatorTest extends TestCase
{
    public function testKeepsGlobalOrderAndCountsItemsOnceButVisitsEveryTime(): void
    {
        $runs = (new ProcessRunReconstructor())->reconstruct(
            $this->template(),
            $this->definition(),
            [
                $this->event('one-start', 'start', 'before', 'item-1', 0),
                $this->event('one-start-after', 'start', 'after', 'item-1', 1),
                $this->event('one-a', 'A', 'after', 'item-1', 2),
                $this->event('one-b', 'B', 'after', 'item-1', 3),
                $this->event('one-a-again', 'A', 'after', 'item-1', 4),
                $this->event('one-b-again', 'B', 'after', 'item-1', 5),
                $this->event('one-end', 'end', 'after', 'item-1', 6),
                $this->event('two-start', 'start', 'before', 'item-2', 10),
                $this->event('two-a', 'A', 'after', 'item-2', 11),
                $this->event('two-b', 'B', 'after', 'item-2', 12),
                $this->event('two-end', 'end', 'after', 'item-2', 13),
            ],
            [new ProcessVersion(null, 'review', '1', new DateTimeImmutable('2026-01-01T00:00:00Z'), templateVersion: '1')]
        );

        self::assertSame(['start', 'A', 'B', 'A', 'B', 'end'], array_map(static fn ($step): string => $step->stepKey, $runs[0]->observedStepSequence));
        self::assertSame('1', $runs[0]->historicalTemplateVersion);
        self::assertSame(['start', 'A', 'B', 'A', 'B'], array_map(static fn ($transition): string => $transition->fromStep, $runs[0]->observedTransitions));

        $aggregates = (new ProcessTransitionAggregator())->aggregate($runs);
        $aToB = array_values(array_filter($aggregates, static fn ($aggregate): bool => $aggregate->fromStep === 'A' && $aggregate->toStep === 'B'))[0];
        self::assertSame(2, $aToB->itemCount);
        self::assertSame(3, $aToB->visitCount);
    }

    public function testAmbiguousRunDoesNotContributeTransitions(): void
    {
        $events = [
            $this->event('start-1', 'start', 'before', 'item-1', 0),
            $this->event('start-2', 'start', 'before', 'item-1', 1),
            $this->event('a', 'A', 'after', 'item-1', 2),
            $this->event('end', 'end', 'after', 'item-1', 3),
        ];
        $runs = (new ProcessRunReconstructor())->reconstruct($this->template(), $this->definition(), $events, [
            new ProcessVersion(null, 'review', '1', new DateTimeImmutable('2026-01-01T00:00:00Z'), templateVersion: '1'),
        ]);

        self::assertSame([], (new ProcessTransitionAggregator())->aggregate($runs));
    }

    public function testMissingStartStillContributesAnUnambiguousObservedSequence(): void
    {
        $runs = (new ProcessRunReconstructor())->reconstruct($this->template(), $this->definition(), [
            $this->event('a', 'A', 'after', 'item-1', 1),
            $this->event('end', 'end', 'after', 'item-1', 2),
        ], [new ProcessVersion(null, 'review', '1', new DateTimeImmutable('2026-01-01T00:00:00Z'), templateVersion: '1')]);

        $aggregates = (new ProcessTransitionAggregator())->aggregate($runs);

        self::assertCount(1, $aggregates);
        self::assertSame('A', $aggregates[0]->fromStep);
        self::assertSame('end', $aggregates[0]->toStep);
        self::assertNull($runs[0]->e2eDuration->seconds);
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate('review', '1', initialStepKey: 'start', steps: array_map(
            static fn (string $key): ProcessTemplateStep => new ProcessTemplateStep($key),
            ['start', 'A', 'B', 'end']
        ));
    }

    private function definition(): ProcessKpiDefinition
    {
        return ProcessKpiDefinition::forTemplate($this->template(), '1', KpiEventMarker::at('start', 'before'), [[KpiEventMarker::at('end', 'after')]]);
    }

    private function event(string $key, string $step, string $phase, string $item, int $minute): ProcessEventRecord
    {
        $time = new DateTimeImmutable(sprintf('2026-01-01T00:%02d:00+00:00', $minute));

        return new ProcessEventRecord(null, $key, 'acme', 'review', $step.'_'.$phase, $step, $item, null, 1, null, $time, $time, '{}', '{}', eventPhase: $phase);
    }
}
