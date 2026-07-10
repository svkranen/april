<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\JourneyDocumentCandidateProvider;
use App\Intelligence\Application\JourneyDocumentCheckService;
use App\Intelligence\Application\JourneyTemplateCheckService;
use App\Intelligence\Application\JourneyTemplateMatchResolver;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateMatch;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateTransition;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use App\Intelligence\Infrastructure\Process\InMemoryProcessDocumentUuidProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class JourneyDocumentCandidateProviderTest extends TestCase
{
    public function testDocumentWithExplicitMatchProcessIsCandidate(): void
    {
        $provider = $this->candidateProvider([
            $this->event(1, 'uuid-1', 'RM_TEST_aufmass', '2026-06-01T09:05:00+00:00'),
        ]);

        $result = $provider->candidates($this->template());

        self::assertSame(['RM_TEST_aufmass'], $result->matchProcessKeys);
        self::assertSame(['uuid-1'], array_map(static fn ($ref): string => $ref->documentUuid, $result->documentRefs));
    }

    public function testDocumentWithOnlySharedOptionalIntakeIsNotCandidate(): void
    {
        $provider = $this->candidateProvider([
            $this->event(1, 'uuid-1', 'RM_TEST_dokumenten_eingang', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'uuid-1', 'irgendein_anderer_prozess', '2026-06-01T09:05:00+00:00'),
        ]);

        self::assertSame([], $provider->candidates($this->template())->documentRefs);
    }

    public function testMultipleMatchProcessesUseOrSemanticsAndDeduplicateDocuments(): void
    {
        $provider = $this->candidateProvider([
            $this->event(1, 'uuid-1', 'RM_TEST_aufmass', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'uuid-1', 'RM_TEST_NevarisExport', '2026-06-01T09:05:00+00:00'),
            $this->event(3, 'uuid-2', 'RM_TEST_NevarisExport', '2026-06-01T09:10:00+00:00'),
        ]);

        $result = $provider->candidates(new ProcessTemplate(
            'rm_aufmass_journey',
            scope: 'journey',
            match: new ProcessTemplateMatch(['RM_TEST_aufmass', 'RM_TEST_NevarisExport'])
        ));

        self::assertSame(['uuid-1', 'uuid-2'], array_map(static fn ($ref): string => $ref->documentUuid, $result->documentRefs));
    }

    public function testSharedOptionalIntakeCanBelongToMultipleJourneyTemplates(): void
    {
        $events = [
            $this->event(1, 'uuid-1', 'RM_TEST_dokumenten_eingang', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'uuid-1', 'RM_TEST_aufmass', '2026-06-01T09:05:00+00:00'),
            $this->event(3, 'uuid-1', 'RM_TEST_service', '2026-06-01T09:10:00+00:00'),
        ];
        $provider = $this->candidateProvider($events);

        $aufmass = $this->template();
        $service = new ProcessTemplate(
            'service_journey',
            scope: 'journey',
            match: new ProcessTemplateMatch(['RM_TEST_service']),
            steps: [
                new ProcessTemplateStep('intake', type: 'process', processKey: 'RM_TEST_dokumenten_eingang', required: false),
                new ProcessTemplateStep('service', type: 'process', processKey: 'RM_TEST_service'),
            ]
        );

        self::assertSame(['uuid-1'], array_map(static fn ($ref): string => $ref->documentUuid, $provider->candidates($aufmass)->documentRefs));
        self::assertSame(['uuid-1'], array_map(static fn ($ref): string => $ref->documentUuid, $provider->candidates($service)->documentRefs));
    }

    public function testFallbackUsesFirstRequiredProcessStepAndSkipsOptionalFirstStep(): void
    {
        $template = new ProcessTemplate(
            'legacy_journey',
            scope: 'journey',
            steps: [
                new ProcessTemplateStep('intake', type: 'process', processKey: 'RM_TEST_dokumenten_eingang', required: false),
                new ProcessTemplateStep('aufmass', type: 'process', processKey: 'RM_TEST_aufmass', required: true),
            ]
        );

        $match = (new JourneyTemplateMatchResolver())->resolve($template);

        self::assertSame(['RM_TEST_aufmass'], $match->processKeys);
        self::assertStringContainsString('legacy fallback', $match->warnings[0]);
    }

    public function testJourneyWithoutMatchAndWithoutRequiredProcessStepReturnsWarning(): void
    {
        $template = new ProcessTemplate(
            'unmatchable_journey',
            scope: 'journey',
            steps: [
                new ProcessTemplateStep('intake', type: 'process', processKey: 'RM_TEST_dokumenten_eingang', required: false),
            ]
        );

        $result = $this->candidateProvider([])->candidates($template);

        self::assertSame([], $result->matchProcessKeys);
        self::assertSame([], $result->documentRefs);
        self::assertStringContainsString('no explicit match.any_process', $result->warnings[0]);
    }

    public function testFullTimelineBeforeAndAfterMatchProcessIsChecked(): void
    {
        $events = [
            $this->event(1, 'uuid-1', 'RM_TEST_dokumenten_eingang', '2026-06-01T09:00:00+00:00'),
            $this->event(2, 'uuid-1', 'RM_TEST_aufmass', '2026-06-01T09:05:00+00:00'),
            $this->event(3, 'uuid-1', 'RM_TEST_NevarisExport', '2026-06-01T09:10:00+00:00'),
        ];
        $service = new JourneyDocumentCheckService(
            $this->candidateProvider($events),
            new JourneyTemplateCheckService(new InMemoryDocumentTimelineProvider([], $events))
        );

        $report = $service->checkDocuments($this->template());

        self::assertCount(1, $report->rows);
        self::assertSame(JourneyTemplateCheckService::STATUS_SATISFIED, $report->rows[0]->status());
        self::assertSame(
            [
                JourneyTemplateCheckService::STEP_PROCESS_EXISTS,
                JourneyTemplateCheckService::STEP_PROCESS_EXISTS,
                JourneyTemplateCheckService::STEP_PROCESS_EXISTS,
            ],
            array_map(static fn ($stepResult): string => $stepResult->status, $report->rows[0]->result->stepResults)
        );
    }

    /**
     * @param array<int, ProcessEventRecord> $events
     */
    private function candidateProvider(array $events): JourneyDocumentCandidateProvider
    {
        return new JourneyDocumentCandidateProvider(new InMemoryProcessDocumentUuidProvider($events));
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(
            'rm_aufmass_journey',
            scope: 'journey',
            match: new ProcessTemplateMatch(['RM_TEST_aufmass']),
            steps: [
                new ProcessTemplateStep('RM_aufmass_document_intake', type: 'process', processKey: 'RM_TEST_dokumenten_eingang', required: false),
                new ProcessTemplateStep('RM_aufmass_intake', type: 'process', processKey: 'RM_TEST_aufmass', required: true),
                new ProcessTemplateStep('RM_aufmass_nev_export', type: 'process', processKey: 'RM_TEST_NevarisExport', required: true),
            ],
            transitions: [
                new ProcessTemplateTransition('RM_aufmass_document_intake', 'RM_aufmass_intake'),
                new ProcessTemplateTransition('RM_aufmass_intake', 'RM_aufmass_nev_export'),
            ]
        );
    }

    private function event(int $id, string $documentUuid, string $processKey, string $occurredAt): ProcessEventRecord
    {
        $time = new DateTimeImmutable($occurredAt);

        return new ProcessEventRecord(
            $id,
            sprintf('evt-%d', $id),
            'test',
            $processKey,
            'start',
            'start',
            'doc-'.$documentUuid,
            $documentUuid,
            1,
            'user-1',
            $time,
            $time,
            '{}',
            '{}',
            1
        );
    }
}
