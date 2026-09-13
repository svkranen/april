<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\KpiEventMarker as Marker;
use App\Intelligence\Domain\ProcessKpiDefinition;
use App\Intelligence\Domain\ProcessRunReconstructor;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Tests\Intelligence\Domain\Fixtures\KpiScenario as Scenario;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProcessKpiDefinitionTest extends TestCase
{
    public function testParallelCompletionRequiresEveryBranch(): void
    {
        $template = $this->parallelTemplate();
        $definition = ProcessKpiDefinition::forTemplate($template, '1', Marker::at('start', 'before'), [
            [Marker::at('A', 'after'), Marker::at('B', 'after')],
        ]);
        $events = [Scenario::event('s', 'start', 'before', '2026-02-01T10:00:00Z'),
            Scenario::event('a', 'A', 'after', '2026-02-01T11:00:00Z')];
        $reconstructor = new ProcessRunReconstructor();
        self::assertSame('running', $reconstructor->reconstruct($template, $definition, $events, Scenario::versions())[0]->status);
        $events[] = Scenario::event('b', 'B', 'after', '2026-02-01T12:00:00Z');
        $run = $reconstructor->reconstruct($template, $definition, $events, Scenario::versions())[0];
        self::assertSame('completed', $run->status);
        self::assertSame(7200.0, $run->e2eDuration->seconds);
    }

    public function testSingleParallelBranchCannotBeConfiguredAsProcessCompletion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProcessKpiDefinition::forTemplate($this->parallelTemplate(), '1', Marker::at('start', 'before'), [[Marker::at('A', 'after')]]);
    }

    public function testExplicitProcessJoinDoesNotRequireOptionalSteps(): void
    {
        $template = $this->parallelTemplate();
        $definition = ProcessKpiDefinition::forTemplate($template, '1', Marker::at('start', 'before'), [[Marker::at('end', 'after')]]);
        $run = (new ProcessRunReconstructor())->reconstruct($template, $definition, Scenario::linear(), Scenario::versions())[0];
        self::assertSame('completed', $run->status); // Conformance is evaluated elsewhere, not inferred here.
    }

    public function testAlternativeTerminalMarkersAreSupported(): void
    {
        $definition = ProcessKpiDefinition::forTemplate(Scenario::template(), '1', Marker::at('start', 'before'), [
            [Marker::at('end', 'after')], [Marker::at('B', 'after')],
        ]);
        $events = [Scenario::event('s', 'start', 'before', '2026-02-01T10:00:00Z'),
            Scenario::event('b', 'B', 'after', '2026-02-01T11:00:00Z')];
        $run = (new ProcessRunReconstructor())->reconstruct(Scenario::template(), $definition, $events, Scenario::versions())[0];
        self::assertSame(3600.0, $run->e2eDuration->seconds);
    }

    public function testDefinitionCannotBeAppliedToAnotherTemplateRevision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProcessRunReconstructor())->reconstruct(new ProcessTemplate('review', '2'), Scenario::definition(), [], []);
    }

    public function testUnknownMarkerIsRejectedAtDefinitionBoundary(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ProcessKpiDefinition::forTemplate(Scenario::template(), '1', Marker::at('missing', 'before'), [[Marker::at('end', 'after')]]);
    }

    private function parallelTemplate(): ProcessTemplate
    {
        return new ProcessTemplate('review', '1', initialStepKey: 'start', steps: Scenario::template()->steps,
            parallelGroups: [new ProcessTemplateParallelGroup('branches', 'start', ['A', 'B'], nextStepKey: 'end')], sourceSystem: 'acme');
    }
}
