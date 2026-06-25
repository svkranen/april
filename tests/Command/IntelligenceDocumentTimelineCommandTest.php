<?php

namespace App\Tests\Command;

use App\Command\IntelligenceDocumentTimelineCommand;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\VisibilityCheckEvaluationResult;
use App\Intelligence\Application\VisibilityCheckResultSaveContext;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessInstance;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateDecisionPoint;
use App\Intelligence\Domain\ProcessTemplateDecisionRule;
use App\Intelligence\Domain\ProcessTemplateRuleCondition;
use App\Intelligence\Infrastructure\Access\InMemoryVisibilityCheckResultStore;
use App\Intelligence\Infrastructure\Process\InMemoryDocumentTimelineProvider;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class IntelligenceDocumentTimelineCommandTest extends TestCase
{
    public function testRendersEventsForVersionOneAndTwoChronologically(): void
    {
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($this->provider()));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            '--format' => 'json',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('uuid-1', $data['documentUuid']);
        self::assertCount(2, $data['instances']);
        self::assertSame(1, $data['instances'][0]['documentVersion']);
        self::assertSame(2, $data['instances'][1]['documentVersion']);
        self::assertSame(['evt-1', 'evt-2', 'evt-3'], array_column($data['events'], 'externalEventKey'));
        self::assertSame([1, 1, 2], array_column($data['events'], 'documentVersion'));
    }

    public function testRendersProcessInstanceIdAndContextSummary(): void
    {
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($this->provider()));

        $exitCode = $tester->execute(['documentUuid' => 'uuid-1']);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Dokument-UUID:', $display);
        self::assertStringContainsString('uuid-1', $display);
        self::assertStringContainsString('processInstanceId', $display);
        self::assertStringContainsString('11', $display);
        self::assertStringContainsString('12', $display);
        self::assertStringContainsString('amount,department', $display);
        self::assertStringContainsString('1 Warnung(en)', $display);
    }

    public function testTimelineShowsJumpBackToPreviousStep(): void
    {
        $firstAt = new DateTimeImmutable('2026-05-29T09:00:00+00:00');
        $secondAt = new DateTimeImmutable('2026-05-29T10:00:00+00:00');
        $thirdAt = new DateTimeImmutable('2026-05-29T11:00:00+00:00');
        $provider = new InMemoryDocumentTimelineProvider(
            [],
            [
                new ProcessEventRecord(
                    1,
                    'evt-received-1',
                    'amagno',
                    'invoice-process',
                    'received',
                    'received',
                    'doc-1',
                    'uuid-jump',
                    1,
                    'user-1',
                    $firstAt,
                    $firstAt,
                    '{}',
                    '{}'
                ),
                new ProcessEventRecord(
                    2,
                    'evt-approved-1',
                    'amagno',
                    'invoice-process',
                    'approved',
                    'approved',
                    'doc-1',
                    'uuid-jump',
                    1,
                    'user-2',
                    $secondAt,
                    $secondAt,
                    '{}',
                    '{}'
                ),
                new ProcessEventRecord(
                    3,
                    'evt-received-2',
                    'amagno',
                    'invoice-process',
                    'received',
                    'received',
                    'doc-1',
                    'uuid-jump',
                    1,
                    'user-1',
                    $thirdAt,
                    $thirdAt,
                    '{}',
                    '{}'
                ),
            ]
        );
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($provider));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-jump',
            '--format' => 'json',
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['received', 'approved', 'received'], array_column($data['events'], 'stepKey'));
        self::assertSame(['evt-received-1', 'evt-approved-1', 'evt-received-2'], array_column($data['events'], 'externalEventKey'));
    }

    public function testTimelineShowsEventPhase(): void
    {
        $beforeAt = new DateTimeImmutable('2026-05-29T09:00:00+00:00');
        $afterAt = new DateTimeImmutable('2026-05-29T09:01:00+00:00');
        $provider = new InMemoryDocumentTimelineProvider(
            [],
            [
                new ProcessEventRecord(
                    1,
                    'evt-before',
                    'amagno',
                    'invoice-process',
                    'approved',
                    'approved',
                    'doc-1',
                    'uuid-phase',
                    1,
                    'user-1',
                    $beforeAt,
                    $beforeAt,
                    '{}',
                    '{}',
                    null,
                    'before'
                ),
                new ProcessEventRecord(
                    2,
                    'evt-after',
                    'amagno',
                    'invoice-process',
                    'approved',
                    'approved',
                    'doc-1',
                    'uuid-phase',
                    1,
                    'user-1',
                    $afterAt,
                    $afterAt,
                    '{}',
                    '{}',
                    null,
                    'after'
                ),
            ]
        );
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($provider));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-phase',
            '--format' => 'json',
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['before', 'after'], array_column($data['events'], 'eventPhase'));

        $tester->execute(['documentUuid' => 'uuid-phase']);
        self::assertStringContainsString('eventPhase', $tester->getDisplay());
        self::assertStringContainsString('before', $tester->getDisplay());
        self::assertStringContainsString('after', $tester->getDisplay());
    }

    public function testTimelineSortsSameOccurredAtByReceivedAtByDefault(): void
    {
        $occurredAt = new DateTimeImmutable('2026-05-29T09:00:00+00:00');
        $provider = new InMemoryDocumentTimelineProvider(
            [],
            [
                new ProcessEventRecord(
                    2,
                    'evt-after-later',
                    'amagno',
                    'invoice-process',
                    'approved',
                    'approved',
                    'doc-1',
                    'uuid-same-time',
                    1,
                    'user-1',
                    $occurredAt,
                    new DateTimeImmutable('2026-05-29T09:00:03+00:00'),
                    '{}',
                    '{}',
                    null,
                    'after'
                ),
                new ProcessEventRecord(
                    3,
                    'evt-before',
                    'amagno',
                    'invoice-process',
                    'approved',
                    'approved',
                    'doc-1',
                    'uuid-same-time',
                    1,
                    'user-1',
                    $occurredAt,
                    new DateTimeImmutable('2026-05-29T09:00:02+00:00'),
                    '{}',
                    '{}',
                    null,
                    'before'
                ),
                new ProcessEventRecord(
                    1,
                    'evt-after-early',
                    'amagno',
                    'invoice-process',
                    'approved',
                    'approved',
                    'doc-1',
                    'uuid-same-time',
                    1,
                    'user-1',
                    $occurredAt,
                    new DateTimeImmutable('2026-05-29T09:00:01+00:00'),
                    '{}',
                    '{}',
                    null,
                    'after'
                ),
            ]
        );
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($provider));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-same-time',
            '--format' => 'json',
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['evt-after-early', 'evt-before', 'evt-after-later'], array_column($data['events'], 'externalEventKey'));
    }

    public function testTimelineReceivedAtOptionSortsByReceivedAt(): void
    {
        $provider = new InMemoryDocumentTimelineProvider(
            [],
            [
                new ProcessEventRecord(
                    1,
                    'evt-occurred-first',
                    'amagno',
                    'invoice-process',
                    'first',
                    'first',
                    'doc-1',
                    'uuid-received-order',
                    1,
                    'user-1',
                    new DateTimeImmutable('2026-05-29T09:00:00+00:00'),
                    new DateTimeImmutable('2026-05-29T09:00:03+00:00'),
                    '{}',
                    '{}'
                ),
                new ProcessEventRecord(
                    2,
                    'evt-received-first',
                    'amagno',
                    'invoice-process',
                    'second',
                    'second',
                    'doc-1',
                    'uuid-received-order',
                    1,
                    'user-1',
                    new DateTimeImmutable('2026-05-29T10:00:00+00:00'),
                    new DateTimeImmutable('2026-05-29T09:00:01+00:00'),
                    '{}',
                    '{}'
                ),
            ]
        );
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($provider));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-received-order',
            '--format' => 'json',
            '--order-by' => 'received-at',
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['evt-received-first', 'evt-occurred-first'], array_column($data['events'], 'externalEventKey'));
    }

    public function testDefaultTimelineOrderIsStableAndDeterministic(): void
    {
        $sameAt = new DateTimeImmutable('2026-05-29T09:00:00+00:00');
        $provider = new InMemoryDocumentTimelineProvider(
            [],
            [
                new ProcessEventRecord(3, 'evt-3', 'amagno', 'invoice-process', 'third', 'third', 'doc-1', 'uuid-stable-order', 1, 'user-1', $sameAt, $sameAt, '{}', '{}'),
                new ProcessEventRecord(1, 'evt-1', 'amagno', 'invoice-process', 'first', 'first', 'doc-1', 'uuid-stable-order', 1, 'user-1', $sameAt, $sameAt, '{}', '{}'),
                new ProcessEventRecord(2, 'evt-2', 'amagno', 'invoice-process', 'second', 'second', 'doc-1', 'uuid-stable-order', 1, 'user-1', $sameAt, $sameAt, '{}', '{}'),
            ]
        );
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($provider));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-stable-order',
            '--format' => 'json',
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['evt-1', 'evt-2', 'evt-3'], array_column($data['events'], 'externalEventKey'));
    }

    public function testEmptyDocumentRendersHelpfulMessage(): void
    {
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($this->provider()));

        $exitCode = $tester->execute(['documentUuid' => 'missing-uuid']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('missing-uuid', $tester->getDisplay());
        self::assertStringContainsString('Keine Prozessinstanzen oder Events fuer dieses Dokument gefunden.', $tester->getDisplay());
    }

    public function testRejectsInvalidFormat(): void
    {
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($this->provider()));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            '--format' => 'xml',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid --format', $tester->getDisplay());
    }

    public function testTimelineJsonCanIncludeContextDiffsAndDecisionRelevantChanges(): void
    {
        $firstAt = new DateTimeImmutable('2026-06-01T09:00:00+00:00');
        $secondAt = new DateTimeImmutable('2026-06-01T09:10:00+00:00');
        $provider = new InMemoryDocumentTimelineProvider(
            [],
            [
                new ProcessEventRecord(1, 'evt-1', 'amagno', 'invoice-process', 'checked', 'checked', 'doc-1', 'uuid-context', 1, 'user-1', $firstAt, $firstAt, '{}', '{}'),
                new ProcessEventRecord(2, 'evt-2', 'amagno', 'invoice-process', 'approved', 'approved', 'doc-1', 'uuid-context', 1, 'user-1', $secondAt, $secondAt, '{}', '{}'),
            ],
            [
                new ContextSnapshot(
                    new DocumentRef('amagno', 'doc-1', 'uuid-context', 1),
                    $firstAt,
                    [
                        'amount_net' => 4149788,
                        'invoice_direction' => 'in',
                    ],
                    [],
                    'invoice-process',
                    'evt-1'
                ),
                new ContextSnapshot(
                    new DocumentRef('amagno', 'doc-1', 'uuid-context', 1),
                    $secondAt,
                    [
                        'amount_net' => 41.49,
                        'invoice_direction' => 'in',
                        'cost_center' => null,
                    ],
                    [],
                    'invoice-process',
                    'evt-2'
                ),
            ]
        );
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($provider, $this->templateProvider()));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-context',
            'processKey' => 'invoice-process',
            '--format' => 'json',
            '--with-context' => true,
            '--with-diff' => true,
            '--with-decisions' => true,
        ]);
        $data = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(4149788, $data['events'][0]['context']['amount_net']);
        self::assertSame([], $data['events'][0]['contextDiff']);
        self::assertSame('changed', $data['events'][1]['contextDiff'][0]['type']);
        self::assertSame('amount_net', $data['events'][1]['contextDiff'][0]['field']);
        self::assertSame(4149788, $data['events'][1]['contextDiff'][0]['from']);
        self::assertSame(41.49, $data['events'][1]['contextDiff'][0]['to']);
        self::assertSame('added', $data['events'][1]['contextDiff'][1]['type']);
        self::assertSame('cost_center', $data['events'][1]['contextDiff'][1]['field']);
        self::assertSame(['route_after_pruefung', 'freigabe_ab_1000'], $data['events'][1]['ruleRelevantContextChanges'][0]['affected_decisions']);
    }

    public function testTimelineTextShowsContextDiffsAndDecisionRelevantChanges(): void
    {
        $firstAt = new DateTimeImmutable('2026-06-01T09:00:00+00:00');
        $secondAt = new DateTimeImmutable('2026-06-01T09:10:00+00:00');
        $provider = new InMemoryDocumentTimelineProvider(
            [],
            [
                new ProcessEventRecord(1, 'evt-1', 'amagno', 'invoice-process', 'checked', 'checked', 'doc-1', 'uuid-context-text', 1, 'user-1', $firstAt, $firstAt, '{}', '{}'),
                new ProcessEventRecord(2, 'evt-2', 'amagno', 'invoice-process', 'approved', 'approved', 'doc-1', 'uuid-context-text', 1, 'user-1', $secondAt, $secondAt, '{}', '{}'),
            ],
            [
                new ContextSnapshot(new DocumentRef('amagno', 'doc-1', 'uuid-context-text', 1), $firstAt, ['amount_net' => 4149788], [], 'invoice-process', 'evt-1'),
                new ContextSnapshot(new DocumentRef('amagno', 'doc-1', 'uuid-context-text', 1), $secondAt, ['amount_net' => 41.49], [], 'invoice-process', 'evt-2'),
            ]
        );
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($provider, $this->templateProvider()));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-context-text',
            'processKey' => 'invoice-process',
            '--with-context' => true,
            '--with-diff' => true,
            '--with-decisions' => true,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Context Timeline', $display);
        self::assertStringContainsString('amount_net: 4149788 -> 41.49 (changed)', $display);
        self::assertStringContainsString('Rule-relevant context change:', $display);
        self::assertStringContainsString('affected decisions: route_after_pruefung, freigabe_ab_1000', $display);
    }

    public function testTimelineJsonContainsAccessResultsOnlyWithAccessOption(): void
    {
        $store = $this->accessResultStore();
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($this->provider(), accessResultProvider: $store));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'invoice-process',
            '--format' => 'json',
        ]);
        $withoutAccess = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertArrayNotHasKey('accessResults', $withoutAccess);

        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($this->provider(), accessResultProvider: $store));
        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'invoice-process',
            '--format' => 'json',
            '--with-access' => true,
        ]);
        $withAccess = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertCount(2, $withAccess['accessResults']);
        self::assertSame('approved', $withAccess['accessResults'][0]['stepKey']);
        self::assertSame('approval_location_a_today', $withAccess['accessResults'][0]['probeKey']);
        self::assertCount(3, $withAccess['events']);
    }

    public function testTimelineTextGroupsAccessResultsAtStepKey(): void
    {
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand($this->provider(), accessResultProvider: $this->accessResultStore()));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-1',
            'processKey' => 'invoice-process',
            '--with-access' => true,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Sichtbarkeitspruefungen', $display);
        self::assertStringContainsString('stepKey=approved eventPhase=after checkKey=route_to_location_approval', $display);
        self::assertStringContainsString('approval_location_a_today expected=visible actual=visible status=ok', $display);
        self::assertStringContainsString('external_today expected=hidden actual=visible status=violation reason=forbidden_visibility', $display);
    }

    public function testTimelineWithAccessShowsStoredResultsEvenWithoutEvents(): void
    {
        $store = new InMemoryVisibilityCheckResultStore();
        $store->save(
            new VisibilityCheckEvaluationResult('uuid-access-only', 'invoice-process', 'approved', 'after', 'route_to_location_approval', 'approval_location_a', 'approval_location_a_today', 'visible', 'visible', 'ok'),
            new VisibilityCheckResultSaveContext(sourceSystem: 'amagno')
        );
        $tester = new CommandTester(new IntelligenceDocumentTimelineCommand(new InMemoryDocumentTimelineProvider(), accessResultProvider: $store));

        $exitCode = $tester->execute([
            'documentUuid' => 'uuid-access-only',
            'processKey' => 'invoice-process',
            '--with-access' => true,
        ]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Keine Prozessinstanzen oder Events fuer dieses Dokument gefunden.', $display);
        self::assertStringContainsString('Sichtbarkeitspruefungen', $display);
        self::assertStringContainsString('approval_location_a_today expected=visible actual=visible status=ok', $display);
    }

    private function templateProvider(): ProcessTemplateProvider
    {
        return new class implements ProcessTemplateProvider {
            public function findByProcessKey(string $processKey): ?ProcessTemplate
            {
                if ($processKey !== 'invoice-process') {
                    return null;
                }

                return new ProcessTemplate(
                    'invoice-process',
                    decisionPoints: [
                        new ProcessTemplateDecisionPoint(
                            'route_after_pruefung',
                            'checked',
                            ['invoice_direction', 'amount_net'],
                            [
                                new ProcessTemplateDecisionRule(
                                    new ProcessTemplateRuleCondition('invoice_direction', 'eq', 'in'),
                                    'approved'
                                ),
                            ]
                        ),
                        new ProcessTemplateDecisionPoint(
                            'freigabe_ab_1000',
                            'checked',
                            ['amount_net'],
                            [
                                new ProcessTemplateDecisionRule(
                                    new ProcessTemplateRuleCondition('amount_net', 'gt', 1000),
                                    'approved'
                                ),
                            ]
                        ),
                    ]
                );
            }
        };
    }

    private function provider(): InMemoryDocumentTimelineProvider
    {
        $firstAt = new DateTimeImmutable('2026-05-29T09:00:00+00:00');
        $secondAt = new DateTimeImmutable('2026-05-29T10:00:00+00:00');
        $thirdAt = new DateTimeImmutable('2026-05-29T11:00:00+00:00');

        return new InMemoryDocumentTimelineProvider(
            [
                new ProcessInstance(
                    11,
                    'amagno',
                    'invoice-process',
                    'draft',
                    'doc-1',
                    'uuid-1',
                    1,
                    'running',
                    'approved',
                    $firstAt,
                    $secondAt,
                    null,
                    $firstAt,
                    $secondAt,
                    ['evt-1', 'evt-2']
                ),
                new ProcessInstance(
                    12,
                    'amagno',
                    'invoice-process',
                    'draft',
                    'doc-1',
                    'uuid-1',
                    2,
                    'running',
                    'received',
                    $thirdAt,
                    $thirdAt,
                    null,
                    $thirdAt,
                    $thirdAt,
                    ['evt-3']
                ),
            ],
            [
                new ProcessEventRecord(
                    3,
                    'evt-3',
                    'amagno',
                    'invoice-process',
                    'received',
                    'received',
                    'doc-1',
                    'uuid-1',
                    2,
                    'user-1',
                    $thirdAt,
                    $thirdAt,
                    '{}',
                    '{}',
                    12
                ),
                new ProcessEventRecord(
                    1,
                    'evt-1',
                    'amagno',
                    'invoice-process',
                    'received',
                    'received',
                    'doc-1',
                    'uuid-1',
                    1,
                    'user-1',
                    $firstAt,
                    $firstAt,
                    '{}',
                    '{}',
                    11
                ),
                new ProcessEventRecord(
                    2,
                    'evt-2',
                    'amagno',
                    'invoice-process',
                    'approved',
                    'approved',
                    'doc-1',
                    'uuid-1',
                    1,
                    'user-2',
                    $secondAt,
                    $secondAt,
                    '{}',
                    '{}',
                    11
                ),
            ],
            [
                new ContextSnapshot(
                    new DocumentRef('amagno', 'doc-1', 'uuid-1', 1),
                    $secondAt,
                    [
                        'amount' => 100,
                        'department' => 'finance',
                    ],
                    ['missing cost_center'],
                    'invoice-process',
                    'evt-2',
                    11
                ),
            ]
        );
    }

    private function accessResultStore(): InMemoryVisibilityCheckResultStore
    {
        $store = new InMemoryVisibilityCheckResultStore();
        $store->saveMany(
            [
                new VisibilityCheckEvaluationResult('uuid-1', 'invoice-process', 'approved', 'after', 'route_to_location_approval', 'approval_location_a', 'approval_location_a_today', 'visible', 'visible', 'ok', null, ['documentCount' => 1]),
                new VisibilityCheckEvaluationResult('uuid-1', 'invoice-process', 'approved', 'after', 'route_to_location_approval', 'approval_location_a', 'external_today', 'hidden', 'visible', 'violation', 'forbidden_visibility', ['documentCount' => 1]),
            ],
            new VisibilityCheckResultSaveContext(sourceSystem: 'amagno', documentVersion: 1)
        );

        return $store;
    }
}
