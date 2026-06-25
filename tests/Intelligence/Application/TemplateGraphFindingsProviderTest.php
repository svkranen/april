<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\AttributedFinding;
use App\Intelligence\Application\DocumentCheckResultProvider;
use App\Intelligence\Application\DocumentCheckResultView;
use App\Intelligence\Application\FindingSeverityFilter;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\ProcessTemplateGraphFactory;
use App\Intelligence\Application\TemplateGraphFindingsProvider;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use App\Intelligence\Domain\ProcessDeviation;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateStep;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TemplateGraphFindingsProviderTest extends TestCase
{
    public function testAggregatesVisibilityFindingsPerStepAndProcessDeviationsSeparately(): void
    {
        $visibility = $this->visibilityProvider([
            'doc-1' => [$this->record('01', 'violation'), $this->record('02', 'warning')],
            'doc-2' => [$this->record('01', 'technical_warning')],
        ]);
        $checks = $this->checkProvider([
            'doc-1' => DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(['01'], [], ['Pflichtschritt 02 fehlt'])),
            'doc-2' => DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], [])),
        ]);

        $findings = (new TemplateGraphFindingsProvider($checks, $visibility))
            ->aggregate($this->template(), ['doc-1', 'doc-2'], 50);

        self::assertSame(2, $findings->totalDocuments);
        self::assertSame(2, $findings->processedDocuments);
        self::assertFalse($findings->limitReached);

        // Step 01: violation (critical) + technical_warning (technical) -> worst critical.
        $step01 = $findings->summaryFor('01');
        self::assertNotNull($step01);
        self::assertSame(FindingSeverityFilter::CRITICAL, $step01->status);
        self::assertSame(1, $step01->counts[FindingSeverityFilter::CRITICAL]);
        self::assertSame(1, $step01->counts[FindingSeverityFilter::TECHNICAL]);

        // Step 02: warning only.
        self::assertSame(FindingSeverityFilter::WARNING, $findings->summaryFor('02')->status);

        // Soll/Ist deviation stays process-level, never attributed to a step.
        self::assertSame(1, $findings->processDeviations);
        self::assertSame(0, $findings->processWarnings);
        self::assertSame(0, $findings->processTechnical);
    }

    public function testStepsWithoutFindingsAreOk(): void
    {
        $findings = (new TemplateGraphFindingsProvider(
            $this->checkProvider(['doc-1' => DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))]),
            $this->visibilityProvider(['doc-1' => []])
        ))->aggregate($this->template(), ['doc-1'], 50);

        self::assertSame(FindingSeverityFilter::OK, $findings->summaryFor('01')->status);
        self::assertSame(FindingSeverityFilter::OK, $findings->summaryFor('02')->status);
        self::assertFalse($findings->hasProcessFindings());
    }

    public function testRespectsLimit(): void
    {
        $findings = (new TemplateGraphFindingsProvider(
            $this->checkProvider([
                'doc-1' => DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(['01'], [], ['x'])),
                'doc-2' => DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(['01'], [], ['y'])),
            ]),
            $this->visibilityProvider(['doc-1' => [], 'doc-2' => []])
        ))->aggregate($this->template(), ['doc-1', 'doc-2'], 1);

        self::assertSame(2, $findings->totalDocuments);
        self::assertSame(1, $findings->processedDocuments);
        self::assertTrue($findings->limitReached);
        self::assertSame(1, $findings->processDeviations);
    }

    public function testSingleBrokenDocumentDegradesToTechnicalWithoutFailing(): void
    {
        $visibility = new class implements VisibilityCheckResultProvider {
            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                if ($documentUuid === 'doc-broken') {
                    throw new RuntimeException('gateway down');
                }

                return [];
            }
        };
        $checks = $this->checkProvider([
            'doc-ok' => DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], [])),
        ]);

        $findings = (new TemplateGraphFindingsProvider($checks, $visibility))
            ->aggregate($this->template(), ['doc-broken', 'doc-ok'], 50);

        self::assertSame(2, $findings->processedDocuments);
        self::assertSame(1, $findings->processTechnical);
        // Good document still yields ok steps.
        self::assertSame(FindingSeverityFilter::OK, $findings->summaryFor('01')->status);
    }

    public function testIgnoresRecordsForStepsNotDeclaredByTheTemplate(): void
    {
        $findings = (new TemplateGraphFindingsProvider(
            $this->checkProvider(['doc-1' => DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []))]),
            $this->visibilityProvider(['doc-1' => [$this->record('99 unbekannt', 'violation')]])
        ))->aggregate($this->template(), ['doc-1'], 50);

        self::assertSame(FindingSeverityFilter::OK, $findings->summaryFor('01')->status);
        self::assertSame(FindingSeverityFilter::OK, $findings->summaryFor('02')->status);
    }

    public function testDecisionDeviationIsAttributedToGatewayAndNotCountedProcessWide(): void
    {
        $message = 'Decision rule violation: approval after 01 expected 02 but got 03';
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(
            [], ['01', '03'], [$message], [], [], null, [],
            [ProcessDeviation::decisionRuleViolation($message, 'approval', '01', '03')]
        ));

        $findings = (new TemplateGraphFindingsProvider(
            $this->checkProvider(['doc-1' => $check]),
            $this->visibilityProvider(['doc-1' => []])
        ))->aggregate($this->templateWithDecision(), ['doc-1'], 50);

        $nodeId = ProcessTemplateGraphFactory::gatewayNodeId('approval');
        self::assertSame(FindingSeverityFilter::DEVIATION, $findings->gatewayStatusFor($nodeId));

        self::assertTrue($findings->hasAttributedFindings());
        self::assertCount(1, $findings->attributedFindings);
        $attributed = $findings->attributedFindings[0];
        self::assertSame(AttributedFinding::TARGET_GATEWAY, $attributed->target);
        self::assertSame('approval', $attributed->label);
        self::assertSame(1, $attributed->documentCount);
        // Machine-readable link fields for the "Dokumente anzeigen" deep link.
        self::assertSame('approval', $attributed->decisionKey);
        self::assertTrue($attributed->hasDocuments());
        self::assertTrue($attributed->isGateway());

        // Attributed -> removed from the process-wide bucket, steps untouched.
        self::assertSame(0, $findings->processDeviations);
        self::assertSame(FindingSeverityFilter::OK, $findings->summaryFor('01')->status);
    }

    public function testTransitionDeviationIsAttributedToEdgeNotToANode(): void
    {
        $message = 'Transition violation: 01 expected one of 02 but got 99';
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(
            [], ['01', '99'], [$message], [], [], null, [],
            [ProcessDeviation::transitionViolation($message, '01', '99', ['02'])]
        ));

        $findings = (new TemplateGraphFindingsProvider(
            $this->checkProvider(['doc-1' => $check]),
            $this->visibilityProvider(['doc-1' => []])
        ))->aggregate($this->template(), ['doc-1'], 50);

        self::assertCount(1, $findings->attributedFindings);
        $attributed = $findings->attributedFindings[0];
        self::assertSame(AttributedFinding::TARGET_TRANSITION, $attributed->target);
        self::assertSame('01 → 99', $attributed->label);
        self::assertSame('01', $attributed->transitionFrom);
        self::assertSame('99', $attributed->transitionTo);
        self::assertNull($attributed->decisionKey);
        self::assertFalse($attributed->isGateway());

        // No gateway is coloured and the start node 01 stays OK (not a node finding).
        self::assertSame([], $findings->gatewayStatusByNodeId);
        self::assertSame(0, $findings->processDeviations);
        self::assertSame(FindingSeverityFilter::OK, $findings->summaryFor('01')->status);
    }

    public function testUnstructuredDeviationStaysProcessWide(): void
    {
        // "Missing step" carries no structured detail -> never attributed, no regex.
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(['02'], ['01'], ['Missing step: 02']));

        $findings = (new TemplateGraphFindingsProvider(
            $this->checkProvider(['doc-1' => $check]),
            $this->visibilityProvider(['doc-1' => []])
        ))->aggregate($this->templateWithDecision(), ['doc-1'], 50);

        self::assertFalse($findings->hasAttributedFindings());
        self::assertSame([], $findings->gatewayStatusByNodeId);
        self::assertSame(1, $findings->processDeviations);
    }

    public function testDecisionDeviationForUndeclaredGatewayStaysProcessWide(): void
    {
        $message = 'Decision rule violation: ghost after 01 expected 02 but got 03';
        $check = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult(
            [], ['01', '03'], [$message], [], [], null, [],
            [ProcessDeviation::decisionRuleViolation($message, 'ghost', '01', '03')]
        ));

        $findings = (new TemplateGraphFindingsProvider(
            $this->checkProvider(['doc-1' => $check]),
            $this->visibilityProvider(['doc-1' => []])
        ))->aggregate($this->templateWithDecision(), ['doc-1'], 50);

        self::assertFalse($findings->hasAttributedFindings());
        self::assertNull($findings->gatewayStatusFor(ProcessTemplateGraphFactory::gatewayNodeId('ghost')));
        self::assertSame(1, $findings->processDeviations);
    }

    private function template(): ProcessTemplate
    {
        return new ProcessTemplate(
            key: 'ai-rechnungen',
            steps: [new ProcessTemplateStep('01', '01'), new ProcessTemplateStep('02', '02')],
        );
    }

    private function templateWithDecision(): ProcessTemplate
    {
        return new ProcessTemplate(
            key: 'ai-rechnungen',
            steps: [new ProcessTemplateStep('01', '01'), new ProcessTemplateStep('02', '02')],
            decisionPoints: [new ProcessTemplateDecisionPoint('approval', '01', [], [])],
        );
    }

    /**
     * @param array<string, array<int, VisibilityCheckResultRecord>> $byDocument
     */
    private function visibilityProvider(array $byDocument): VisibilityCheckResultProvider
    {
        return new class($byDocument) implements VisibilityCheckResultProvider {
            /** @param array<string, array<int, VisibilityCheckResultRecord>> $byDocument */
            public function __construct(private readonly array $byDocument)
            {
            }

            public function findByDocument(string $documentUuid, ?string $processKey = null): array
            {
                return $this->byDocument[$documentUuid] ?? [];
            }
        };
    }

    /**
     * @param array<string, DocumentCheckResultView> $byDocument
     */
    private function checkProvider(array $byDocument): DocumentCheckResultProvider
    {
        return new class($byDocument) implements DocumentCheckResultProvider {
            /** @param array<string, DocumentCheckResultView> $byDocument */
            public function __construct(private readonly array $byDocument)
            {
            }

            public function forDocument(ProcessTemplate $template, string $documentUuid): DocumentCheckResultView
            {
                return $this->byDocument[$documentUuid] ?? DocumentCheckResultView::unavailable('missing');
            }
        };
    }

    private function record(string $stepKey, string $status): VisibilityCheckResultRecord
    {
        return new VisibilityCheckResultRecord(
            1, 'doc', 1, 'ai-rechnungen', 'amagno', $stepKey, 'after', 'route', 'profile',
            'external_today', 'amagno_magnet_documents', '1009', 'hidden', 'visible', $status, 'forbidden_visibility',
            new DateTimeImmutable('2026-06-15T10:00:00+00:00'), 1, true, 1, null
        );
    }
}
