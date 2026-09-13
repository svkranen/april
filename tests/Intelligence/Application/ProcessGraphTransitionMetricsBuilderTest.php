<?php

declare(strict_types=1);

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ProcessGraphObservationProjector;
use App\Intelligence\Application\ProcessGraphTransitionMetricsBuilder;
use App\Intelligence\Application\ProcessTemplateGraphFactory;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\ProcessTemplateVersionProvider;
use App\Intelligence\Application\ProcessTransitionAggregator;
use App\Intelligence\Domain\KpiDuration;
use App\Intelligence\Domain\KpiEligibilityResult;
use App\Intelligence\Domain\ProcessRunMeasurement;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Domain\ObservedProcessTransition;
use PHPUnit\Framework\TestCase;

final class ProcessGraphTransitionMetricsBuilderTest extends TestCase
{
    public function testProjectsExpectedCountsAndObservedOnlyKnownNodeEdges(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'demo', 'version' => '1',
            'steps' => [['key' => 'a'], ['key' => 'b'], ['key' => 'c']],
            'transitions' => [['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'c']],
        ]);
        $run = $this->makeRun([
            new ObservedProcessTransition('a', 'b', 1),
            new ObservedProcessTransition('a', 'c', 2),
        ]);
        $provider = new class($template) implements ProcessTemplateProvider, ProcessTemplateVersionProvider {
            public function __construct(private ProcessTemplate $template) {}
            public function findByProcessKey(string $processKey): ?ProcessTemplate { return $this->template; }
            public function findByProcessKeyAndVersion(string $processKey, string $version): ?ProcessTemplate { return $version === $this->template->version ? $this->template : null; }
        };
        $builder = new ProcessGraphTransitionMetricsBuilder(new ProcessTransitionAggregator(), $provider, new ProcessTemplateGraphFactory(), new ProcessGraphObservationProjector());
        $metrics = $builder->build($template, [$run]);
        $byKey = [];
        foreach ($metrics as $metric) { $byKey[$metric->from.'→'.$metric->to] = $metric; }

        self::assertSame(1, $byKey['a→b']->itemCount);
        self::assertSame(1, $byKey['a→b']->visitCount);
        self::assertSame(1, $byKey['a→c']->itemCount);
        self::assertSame(1, $byKey['a→c']->visitCount);
        self::assertTrue($byKey['a→c']->isObservedOnly);
        self::assertFalse($byKey['a→c']->isExpected);
        self::assertSame(1, $byKey['a→c']->deviationCount);
    }

    public function testAggregatesUnknownNodesAndOpenDeviationChain(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'demo', 'version' => '1',
            'steps' => [['key' => 'a'], ['key' => 'b']],
            'transitions' => [['from' => 'a', 'to' => 'b']],
        ]);
        $run = $this->makeRun([
            new ObservedProcessTransition('a', 'x', 1),
            new ObservedProcessTransition('x', 'x', 2),
            new ObservedProcessTransition('x', 'y', 3),
        ], 'running');
        $provider = new class($template) implements ProcessTemplateProvider, ProcessTemplateVersionProvider {
            public function __construct(private ProcessTemplate $template) {}
            public function findByProcessKey(string $processKey): ?ProcessTemplate { return $this->template; }
            public function findByProcessKeyAndVersion(string $processKey, string $version): ?ProcessTemplate { return $this->template; }
        };
        $builder = new ProcessGraphTransitionMetricsBuilder(new ProcessTransitionAggregator(), $provider);
        $nodes = $builder->buildObservedOnlyNodes($template, [$run]);
        self::assertSame(['x', 'y'], array_map(static fn ($node): string => $node->stepKey, $nodes));
        self::assertSame(1, $nodes[0]->itemCount);
        self::assertSame(2, $nodes[0]->visitCount);
        $chains = $builder->buildDeviationChains($template, [$run]);
        self::assertCount(1, $chains);
        self::assertTrue($chains[0]->open);
        self::assertFalse($chains[0]->returnedToExpectedPath);
    }

    /** @param list<ObservedProcessTransition> $transitions */
    private function makeRun(array $transitions, string $status = 'completed'): ProcessRunMeasurement
    {
        $eligibility = new KpiEligibilityResult(true, null, null, null, null, null);
        $sequence = [];
        foreach ($transitions as $index => $transition) {
            if ($index === 0) {
                $sequence[] = new \App\Intelligence\Domain\ObservedStepVisit($transition->fromStep, 1, new \DateTimeImmutable('2026-01-01'), 'e'.$index);
            }
            $sequence[] = new \App\Intelligence\Domain\ObservedStepVisit($transition->toStep, $index + 2, new \DateTimeImmutable('2026-01-01'), 'e'.($index + 1));
        }
        return new ProcessRunMeasurement('run-1', 'demo', '1', '1', [], [], null, null, $status, $eligibility, KpiDuration::between(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-01'), []), [], [], '1', $sequence, $transitions);
    }
}
