<?php

namespace App\Tests\Intelligence\Infrastructure\Demo;

use App\Intelligence\Domain\KpiEventMarker;
use App\Intelligence\Domain\ProcessKpiDefinition;
use App\Intelligence\Domain\ProcessRunReconstructor;
use App\Intelligence\Domain\ProcessVersion;
use App\Intelligence\Infrastructure\Demo\InvoiceKpiDemoFixture;
use App\Intelligence\Application\ProcessTemplateCatalog;
use App\Intelligence\Application\KpiPeriod;
use App\Intelligence\Application\ProcessKpiAggregator;
use App\Intelligence\Application\ProcessKpiMeasurements;
use App\Intelligence\Application\ProcessKpiPageProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessVersionRepository;
use App\Tests\Fake\InMemoryProcessEventReader;
use App\Intelligence\Infrastructure\Template\ConfiguredProcessKpiDefinitionProvider;
use App\Intelligence\Infrastructure\Template\YamlProcessTemplateProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InvoiceKpiDemoFixtureTest extends TestCase
{
    public function testProvidesBothBranchesCoverageAndBoundaryCases(): void
    {
        $fixture = new InvoiceKpiDemoFixture();
        $events = $fixture->events();

        self::assertCount(260, $events);
        self::assertSame(20, count(array_unique(array_map(static fn ($event): string => $event->documentExternalId, $events))));
        self::assertCount(4, array_unique(array_map(static fn ($event): string => json_decode($event->rawPayloadJson, true, 512, JSON_THROW_ON_ERROR)['department'], $events)));
        self::assertNotEmpty(array_filter($events, static fn ($event): bool => $event->stepKey === 'management_approval'));
        self::assertNotEmpty(array_filter($events, static fn ($event): bool => $event->receivedAt > $event->occurredAt));
        self::assertNotEmpty(array_filter($events, static fn ($event): bool => $event->occurredAt->format('Y-m-d') === '2026-02-01'));

        $template = (new YamlProcessTemplateProvider(new ProcessTemplateCatalog(dirname(__DIR__, 4).'/config/april/process-templates')))
            ->findByProcessKey(InvoiceKpiDemoFixture::PROCESS_KEY);
        self::assertNotNull($template);
        $definition = ProcessKpiDefinition::forTemplate(
            $template,
            '1',
            KpiEventMarker::at('invoice_received', 'before'),
            [[KpiEventMarker::at('payment', 'before')]]
        );
        $runs = (new ProcessRunReconstructor())->reconstruct(
            $template,
            $definition,
            $events,
            [new ProcessVersion(null, InvoiceKpiDemoFixture::PROCESS_KEY, '1', new DateTimeImmutable('2026-01-01T00:00:00+00:00'))]
        );

        self::assertCount(20, $runs);
        self::assertCount(1, array_filter($runs, static fn ($run): bool => $run->status === 'running'));
        self::assertGreaterThan(0, count(array_filter($runs, static fn ($run): bool => $run->e2eDuration->isMeasurable())));
        self::assertNotEmpty(array_filter($runs, static fn ($run): bool => count(array_filter($run->stepVisits, static fn ($visit): bool => $visit->measurementPointCoverage === ['before' => true, 'after' => false])) > 0));
        self::assertNotEmpty(array_filter($runs, static fn ($run): bool => count(array_filter($run->stepVisits, static fn ($visit): bool => $visit->measurementPointCoverage === ['before' => false, 'after' => true])) > 0));
        self::assertNotEmpty(array_filter($runs, static fn ($run): bool => count(array_filter($run->stepVisits, static fn ($visit): bool => $visit->stepKey === 'review_assignment')) > 1));

        $versions = new InMemoryProcessVersionRepository([
            new ProcessVersion(null, InvoiceKpiDemoFixture::PROCESS_KEY, '1', new DateTimeImmutable('2026-01-01T00:00:00+00:00')),
        ]);
        $page = (new ProcessKpiPageProvider(
            new YamlProcessTemplateProvider(new ProcessTemplateCatalog(dirname(__DIR__, 4).'/config/april/process-templates')),
            new ConfiguredProcessKpiDefinitionProvider([
                InvoiceKpiDemoFixture::PROCESS_KEY => [
                    'template_version' => '1', 'version' => '1',
                    'start' => ['step' => 'invoice_received', 'phase' => 'before'],
                    'completion_groups' => [[['step' => 'payment', 'phase' => 'before']]],
                ],
            ]),
            new ProcessKpiMeasurements(new InMemoryProcessEventReader($events), $versions),
            new ProcessKpiAggregator()
        ))->build(
            InvoiceKpiDemoFixture::PROCESS_KEY,
            KpiPeriod::fromDates('2026-01-01', '2026-03-01')
        );
        self::assertNotNull($page->summary);
        self::assertSame(19, $page->summary->started);
        self::assertSame(19, $page->summary->completed);
        self::assertSame(1, $page->summary->open);
        self::assertGreaterThan(0, $page->summary->e2e->count);
        self::assertGreaterThan(0, array_sum(array_map(static fn ($step): int => $step->visits, $page->summary->steps)));
    }
}
