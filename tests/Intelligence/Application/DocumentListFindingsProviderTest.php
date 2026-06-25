<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentCheckResultProvider;
use App\Intelligence\Application\DocumentCheckResultView;
use App\Intelligence\Application\DocumentListFindingsProvider;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use App\Intelligence\Domain\ProcessDeviation;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DocumentListFindingsProviderTest extends TestCase
{
    public function testComputesFindingsPerDocument(): void
    {
        $provider = new DocumentListFindingsProvider(
            $this->checkProvider(DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))),
            $this->visibilityProvider([$this->record('violation')])
        );

        $result = $provider->forDocuments($this->template(), ['doc-1', 'doc-2'], 50);

        self::assertCount(2, $result);
        self::assertSame('critical', $result['doc-1']->severity);
        self::assertSame('Kritisch', $result['doc-1']->label);
        self::assertSame(1, $result['doc-1']->countsByCategory['access']);
        // The step-attributable finding exposes its step for the document-list step filter.
        self::assertSame(['01'], $result['doc-1']->stepKeys);
        self::assertTrue($result['doc-1']->hasStep('01'));
    }

    public function testProcessOnlyFindingHasNoStepKeys(): void
    {
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(['01', '02'], ['01'], ['fehlt 02'], [], [], null, []));
        $provider = new DocumentListFindingsProvider($this->checkProvider($check), $this->visibilityProvider([]));

        $result = $provider->forDocuments($this->template(), ['doc-1'], 50);

        // Soll/Ist process deviations carry no stepKey and must not match a step filter.
        self::assertSame([], $result['doc-1']->stepKeys);
        self::assertFalse($result['doc-1']->hasStep('01'));
    }

    public function testProcessDeviationLeadsToDeviationRow(): void
    {
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(['01', '02'], ['01'], ['fehlt 02'], [], [], null, []));
        $provider = new DocumentListFindingsProvider($this->checkProvider($check), $this->visibilityProvider([]));

        $result = $provider->forDocuments($this->template(), ['doc-1'], 50);

        self::assertSame('deviation', $result['doc-1']->severity);
        self::assertSame('Abweichung', $result['doc-1']->label);
    }

    public function testRespectsLimit(): void
    {
        $provider = new DocumentListFindingsProvider(
            $this->checkProvider(DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))),
            $this->visibilityProvider([])
        );

        $result = $provider->forDocuments($this->template(), ['a', 'b', 'c', 'd'], 2);

        self::assertCount(2, $result);
        self::assertArrayHasKey('a', $result);
        self::assertArrayHasKey('b', $result);
        self::assertArrayNotHasKey('c', $result);
    }

    public function testSingleDocumentErrorDegradesToTechnicalRow(): void
    {
        $visibility = new class implements VisibilityCheckResultProvider {
            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                throw new RuntimeException('db down');
            }
        };
        $provider = new DocumentListFindingsProvider(
            $this->checkProvider(DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))),
            $visibility
        );

        $result = $provider->forDocuments($this->template(), ['doc-1'], 50);

        self::assertSame('technical', $result['doc-1']->severity);
        self::assertSame('Technisch', $result['doc-1']->label);
        self::assertSame('db down', $result['doc-1']->error);
    }

    public function testCollectsAttributedDecisionAndTransitionKeys(): void
    {
        $decisionMsg = 'Decision rule violation: approval after 01 expected 02 but got 03';
        $transitionMsg = 'Transition violation: 01 expected one of 02 but got 99';
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(
            [], ['01', '03'], [$decisionMsg, $transitionMsg], [], [], null, [],
            [
                ProcessDeviation::decisionRuleViolation($decisionMsg, 'approval', '01', '03'),
                ProcessDeviation::transitionViolation($transitionMsg, '01', '99', ['02']),
            ]
        ));
        $provider = new DocumentListFindingsProvider($this->checkProvider($check), $this->visibilityProvider([]));

        $result = $provider->forDocuments($this->templateWithDecision(), ['doc-1'], 50);

        self::assertSame(['approval'], $result['doc-1']->decisionKeys);
        self::assertTrue($result['doc-1']->hasDecision('approval'));
        self::assertTrue($result['doc-1']->hasTransition('01', '99'));
        self::assertFalse($result['doc-1']->hasTransition('01', '02'));
    }

    public function testUndeclaredGatewayAndUnstructuredDeviationAreNotCollected(): void
    {
        $ghostMsg = 'Decision rule violation: ghost after 01 expected 02 but got 03';
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(
            ['01', '02'], ['01'], ['fehlt 02', $ghostMsg], [], [], null, [],
            [ProcessDeviation::decisionRuleViolation($ghostMsg, 'ghost', '01', '03')]
        ));
        $provider = new DocumentListFindingsProvider($this->checkProvider($check), $this->visibilityProvider([]));

        $result = $provider->forDocuments($this->templateWithDecision(), ['doc-1'], 50);

        // "ghost" gateway is not declared -> not attributed; "fehlt 02" carries no
        // structured detail -> never attributed (no regex on the message).
        self::assertSame([], $result['doc-1']->decisionKeys);
        self::assertSame([], $result['doc-1']->transitionKeys);
        self::assertFalse($result['doc-1']->hasDecision('ghost'));
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(key: 'ai-rechnungen', version: '1.1');
    }

    private function templateWithDecision(): ProcessTemplate
    {
        return new ProcessTemplate(
            key: 'ai-rechnungen',
            version: '1.1',
            decisionPoints: [new ProcessTemplateDecisionPoint('approval', '01', [], [])],
        );
    }

    private function checkProvider(DocumentCheckResultView $view): DocumentCheckResultProvider
    {
        return new class($view) implements DocumentCheckResultProvider {
            public function __construct(private readonly DocumentCheckResultView $view)
            {
            }

            public function forDocument(ProcessTemplate $template, string $documentUuid): DocumentCheckResultView
            {
                return $this->view;
            }
        };
    }

    /**
     * @param array<int, VisibilityCheckResultRecord> $records
     */
    private function visibilityProvider(array $records): VisibilityCheckResultProvider
    {
        return new class($records) implements VisibilityCheckResultProvider {
            /** @param array<int, VisibilityCheckResultRecord> $records */
            public function __construct(private readonly array $records)
            {
            }

            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                return $this->records;
            }
        };
    }

    private function record(string $status): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            1, 'doc-1', 1, 'ai-rechnungen', 'amagno', '01', 'after', 'route', 'profile',
            'external_today', 'amagno_magnet_documents', '1009', 'hidden', 'visible', $status, 'forbidden_visibility',
            new DateTimeImmutable('2026-06-15T10:00:00+00:00'), 1, true, 1, null
        );
    }
}
