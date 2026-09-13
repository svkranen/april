<?php

declare(strict_types=1);

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\AttributedFinding;
use App\Intelligence\Application\FindingSeverityFilter;
use App\Intelligence\Application\ProcessTemplateGraphFactory;
use App\Intelligence\Application\StepFindingSummary;
use App\Intelligence\Application\TemplateGraphFindings;
use App\Intelligence\Application\TemplateGraphModelBuilder;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Domain\ProcessGraphEdgeMetrics;
use App\Intelligence\Domain\ProcessGraphNodeMetrics;
use PHPUnit\Framework\TestCase;

final class TemplateGraphModelBuilderTest extends TestCase
{
    public function testBuildsVersionedProcessModelWithStructureFindingsAndNavigation(): void
    {
        $model = $this->builder()->build($this->processTemplate(), $this->findings(), '/app/templates/process/documents');
        $data = $model->jsonSerialize();

        self::assertSame('1.0', $data['schemaVersion']);
        self::assertSame('process', $data['graphType']);
        self::assertSame('left-to-right', $data['direction']);
        self::assertSame([], $data['metadata']['match']['anyProcess']);

        $nodes = $this->byId($data['nodes']);
        self::assertSame('activity', $nodes['check']['type']);
        self::assertTrue($nodes['check']['data']['required']);
        self::assertSame('critical', $nodes['check']['state']);
        self::assertSame(2, $nodes['check']['data']['metrics']['findingCount']);
        self::assertSame(
            '/app/templates/process/documents?withFindings=1&step=check',
            $nodes['check']['data']['navigation']['url']
        );
        self::assertFalse($nodes['optional']['data']['required']);
        self::assertTrue($nodes['optional']['data']['optional']);
        self::assertSame('decision', $nodes['decision:route']['type']);
        self::assertSame('parallel', $nodes['parallel_start:work']['type']);
        self::assertSame('parallel', $nodes['parallel_join:work']['type']);

        $transition = array_values(array_filter(
            $data['edges'],
            static fn (array $edge): bool => $edge['from'] === 'check' && $edge['to'] === 'optional'
        ))[0];
        self::assertSame('deviation', $transition['state']);
        self::assertSame(3, $transition['data']['metrics']['documentCount']);
        self::assertSame('edge', $data['overlay']['markers'][1]['kind']);
    }

    public function testBuildsJourneyProcessStepsWithOptionalConditionalAndMatchMetadata(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'journey',
            'version' => '0.1',
            'scope' => 'journey',
            'match' => ['any_process' => ['process-b']],
            'steps' => [
                ['key' => 'entry', 'type' => 'process', 'process_key' => 'process-a', 'required' => false],
                ['key' => 'main', 'type' => 'process', 'process_key' => 'process-b', 'required' => true, 'when' => ['route' => 'standard']],
            ],
            'transitions' => [['from' => 'entry', 'to' => 'main']],
        ]);

        $model = $this->builder()->build($template, null, '/app/templates/journey/documents');
        $data = $model->jsonSerialize();
        $nodes = $this->byId($data['nodes']);

        self::assertSame('journey', $data['graphType']);
        self::assertSame(['process-b'], $data['metadata']['match']['anyProcess']);
        self::assertSame('subprocess', $nodes['entry']['type']);
        self::assertSame('process-a', $nodes['entry']['label']);
        self::assertTrue($nodes['entry']['data']['optional']);
        self::assertTrue($nodes['main']['data']['required']);
        self::assertTrue($nodes['main']['data']['conditional']);
        self::assertSame('process-b', $nodes['main']['data']['processKey']);
    }

    public function testJsonIsStableAndEscapesMarkup(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'escape',
            'steps' => [['key' => 'x', 'name' => '</script><b>unsafe</b>']],
        ]);
        $builder = $this->builder();

        $first = $builder->build($template, null, '/documents')->toJson();
        $second = $builder->build($template, null, '/documents')->toJson();

        self::assertSame($first, $second);
        self::assertStringNotContainsString('</script>', $first);
        self::assertStringContainsString('\\u003C\\/script\\u003E', $first);
        self::assertSame('escape', json_decode($first, true, 512, JSON_THROW_ON_ERROR)['metadata']['template']['key']);
    }

    public function testIncludesObservedOnlyNodesEdgesAndCountsInNeutralPayload(): void
    {
        $template = ProcessTemplateArrayFactory::fromArray([
            'key' => 'simple', 'version' => '1',
            'steps' => [['key' => 'a'], ['key' => 'b']],
            'transitions' => [['from' => 'a', 'to' => 'b']],
        ]);
        $findings = new TemplateGraphFindings([], 1, 1, false, 0, 0, 0,
            transitionMetrics: [
                new ProcessGraphEdgeMetrics('a', 'b', observedCount: 2, itemCount: 2, visitCount: 3),
                new ProcessGraphEdgeMetrics('a', 'x', observedCount: 1, deviationCount: 1, isExpected: false, isObservedOnly: true, itemCount: 1, visitCount: 1),
                new ProcessGraphEdgeMetrics('x', 'b', observedCount: 1, deviationCount: 1, isExpected: false, isObservedOnly: true, itemCount: 1, visitCount: 1),
            ],
            observedOnlyNodes: [new ProcessGraphNodeMetrics(observedCount: 1, deviationCount: 1, itemCount: 1, visitCount: 1, isExpected: false, isObservedOnly: true, stepKey: 'x')]
        );
        $data = $this->builder()->build($template, $findings, '/documents')->jsonSerialize();
        $nodes = $this->byId($data['nodes']);
        self::assertArrayHasKey('x', $nodes);
        self::assertTrue($nodes['x']['data']['isObservedOnly']);
        self::assertSame(1, $nodes['x']['data']['metrics']['itemCount']);
        $edges = array_map(static fn (array $edge): array => $edge, $data['edges']);
        $edge = array_values(array_filter($edges, static fn (array $edge): bool => $edge['from'] === 'a' && $edge['to'] === 'x'))[0];
        self::assertSame('deviation', $edge['state']);
        self::assertSame('1', $edge['label']);
        self::assertSame(1, $edge['data']['metrics']['itemCount']);
    }

    private function builder(): TemplateGraphModelBuilder
    {
        return new TemplateGraphModelBuilder(new ProcessTemplateGraphFactory());
    }

    private function processTemplate(): ProcessTemplate
    {
        return ProcessTemplateArrayFactory::fromArray([
            'key' => 'process',
            'required_steps' => ['check'],
            'steps' => [
                ['key' => 'check'],
                ['key' => 'optional'],
                ['key' => 'parallel-a'],
                ['key' => 'parallel-b'],
                ['key' => 'done'],
            ],
            'transitions' => [
                ['from' => 'check', 'to' => 'optional'],
                ['from' => 'optional', 'to_parallel_group' => 'work'],
            ],
            'decision_points' => [[
                'key' => 'route',
                'after' => 'check',
                'rules' => [['else' => true, 'expect_next' => 'optional']],
            ]],
            'parallel_groups' => [[
                'key' => 'work',
                'required_steps' => ['parallel-a', 'parallel-b'],
                'next' => 'done',
            ]],
        ]);
    }

    private function findings(): TemplateGraphFindings
    {
        return new TemplateGraphFindings(
            ['check' => new StepFindingSummary('check', FindingSeverityFilter::CRITICAL, '2 critical', 2, [
                FindingSeverityFilter::CRITICAL => 2,
            ])],
            5,
            5,
            false,
            1,
            0,
            0,
            ['decision:route' => FindingSeverityFilter::DEVIATION],
            [new AttributedFinding(
                AttributedFinding::TARGET_TRANSITION,
                'check → optional',
                FindingSeverityFilter::DEVIATION,
                'Deviation',
                'Unexpected transition',
                4,
                3,
                transitionFrom: 'check',
                transitionTo: 'optional'
            )]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function byId(array $rows): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            $indexed[$row['id']] = $row;
        }

        return $indexed;
    }
}
