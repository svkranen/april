<?php

namespace App\Command;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineInstanceRow;
use App\Intelligence\Application\DocumentTimelineContextDiffBuilder;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\VisibilityCheckResultProvider;
use App\Intelligence\Application\VisibilityCheckResultRecord;
use App\Intelligence\Domain\ProcessTemplate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:document:timeline',
    description: 'Shows the chronological event timeline for a document.'
)]
final class IntelligenceDocumentTimelineCommand extends Command
{
    public function __construct(
        private readonly DocumentTimelineProvider $timelineProvider,
        private readonly ?ProcessTemplateProvider $templateProvider = null,
        private readonly DocumentTimelineContextDiffBuilder $contextDiffBuilder = new DocumentTimelineContextDiffBuilder(),
        private readonly ?VisibilityCheckResultProvider $accessResultProvider = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('documentUuid', InputArgument::REQUIRED, 'Document UUID to report')
            ->addArgument('processKey', InputArgument::OPTIONAL, 'Optional process key to filter the timeline')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table')
            ->addOption('order-by', null, InputOption::VALUE_REQUIRED, 'Event order: occurred-at, received-at, or occurred-then-received', EventTimelineOrder::DEFAULT->value)
            ->addOption('with-context', null, InputOption::VALUE_NONE, 'Show full context snapshots for timeline events')
            ->addOption('with-diff', null, InputOption::VALUE_NONE, 'Show context changes compared to the previous snapshot')
            ->addOption('with-decisions', null, InputOption::VALUE_NONE, 'Mark context changes that are used by template decision rules')
            ->addOption('with-access', null, InputOption::VALUE_NONE, 'Show persisted access visibility check results grouped by step');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $documentUuid = (string) $input->getArgument('documentUuid');
        $processKey = $input->getArgument('processKey');
        $processKey = is_string($processKey) && trim($processKey) !== '' ? trim($processKey) : null;
        $format = (string) $input->getOption('format');
        $order = EventTimelineOrder::fromOption((string) $input->getOption('order-by'));
        $withContext = $input->getOption('with-context') === true;
        $withDiff = $input->getOption('with-diff') === true;
        $withDecisions = $input->getOption('with-decisions') === true;
        $withAccess = $input->getOption('with-access') === true;

        if (!in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use "table" or "json".</error>');

            return Command::INVALID;
        }

        if ($order === null) {
            $output->writeln(sprintf('<error>Invalid --order-by. Use one of: %s.</error>', implode(', ', EventTimelineOrder::values())));

            return Command::INVALID;
        }

        $report = $this->timelineProvider->build($documentUuid, $order);
        $instances = $this->filterInstances($report->instances, $processKey);
        $events = $this->filterEvents($report->events, $processKey);
        $template = $withDecisions ? $this->templateFor($processKey, $events) : null;
        $decisionFields = $template === null ? [] : $this->decisionFields($template);
        $diffsByEventKey = ($withDiff || $withDecisions) ? $this->contextDiffBuilder->build($events) : [];
        $decisionChangesByEventKey = $withDecisions ? $this->decisionChanges($diffsByEventKey, $decisionFields) : [];
        $accessResults = $withAccess && $this->accessResultProvider !== null
            ? $this->accessResultProvider->findByDocument($documentUuid, $processKey)
            : [];

        if ($format === 'json') {
            $output->writeln(json_encode(
                $this->reportArray($documentUuid, $instances, $events, $withContext, $withDiff, $withDecisions, $diffsByEventKey, $decisionChangesByEventKey, $withAccess, $accessResults),
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
            ));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Dokument-UUID:</info> %s', $documentUuid));
        if ($processKey !== null) {
            $output->writeln(sprintf('<info>Prozess:</info> %s', $processKey));
        }
        if ($instances === [] && $events === []) {
            $output->writeln('<comment>Keine Prozessinstanzen oder Events fuer dieses Dokument gefunden.</comment>');
            if ($withAccess) {
                $this->writeAccessTimeline($output, $accessResults);
            }

            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<comment>Prozessinstanzen je Version</comment>');
        $instanceTable = new Table($output);
        $instanceTable->setHeaders(['ID', 'processKey', 'Dokumentversion', 'currentStepKey', 'Status']);
        foreach ($instances as $instance) {
            $instanceTable->addRow($this->instanceRow($instance));
        }
        $instanceTable->render();

        $output->writeln('');
        $output->writeln('<comment>Events chronologisch</comment>');
        $eventTable = new Table($output);
        $eventTable->setHeaders([
            'externalEventKey',
            'eventKey',
            'stepKey',
            'eventPhase',
            'processKey',
            'Dokumentversion',
            'occurredAt',
            'receivedAt',
            'processInstanceId',
            'duplicate',
            'Context Snapshot',
        ]);
        foreach ($events as $event) {
            $eventTable->addRow($this->eventRow($event));
        }
        $eventTable->render();

        if ($withContext || $withDiff || $withDecisions) {
            $this->writeContextTimeline($output, $events, $withContext, $withDiff, $withDecisions, $diffsByEventKey, $decisionChangesByEventKey);
        }

        if ($withAccess) {
            $this->writeAccessTimeline($output, $accessResults);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<int, DocumentTimelineInstanceRow> $instances
     * @return array<int, DocumentTimelineInstanceRow>
     */
    private function filterInstances(array $instances, ?string $processKey): array
    {
        if ($processKey === null) {
            return $instances;
        }

        return array_values(array_filter(
            $instances,
            static fn (DocumentTimelineInstanceRow $row): bool => $row->processKey === $processKey
        ));
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @return array<int, DocumentTimelineEventRow>
     */
    private function filterEvents(array $events, ?string $processKey): array
    {
        if ($processKey === null) {
            return $events;
        }

        return array_values(array_filter(
            $events,
            static fn (DocumentTimelineEventRow $row): bool => $row->processKey === $processKey
        ));
    }

    /**
     * @return array<int, mixed>
     */
    private function instanceRow(DocumentTimelineInstanceRow $instance): array
    {
        return [
            $instance->id ?? '',
            $instance->processKey,
            $instance->documentVersion,
            $instance->currentStepKey,
            $instance->status,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function eventRow(DocumentTimelineEventRow $event): array
    {
        return [
            $event->externalEventKey,
            $event->eventKey,
            $event->stepKey,
            $event->eventPhase,
            $event->processKey,
            $event->documentVersion,
            $event->occurredAt->format(DATE_ATOM),
            $event->receivedAt->format(DATE_ATOM),
            $event->processInstanceId ?? '',
            $event->duplicate ? 'yes' : 'no',
            $this->contextSummary($event->contextSummary),
        ];
    }

    /**
     * @param array<int, DocumentTimelineInstanceRow> $instances
     * @param array<int, DocumentTimelineEventRow> $events
     * @param array<string, array<int, array<string, mixed>>> $diffsByEventKey
     * @param array<string, array<int, array<string, mixed>>> $decisionChangesByEventKey
     * @param array<int, VisibilityCheckResultRecord> $accessResults
     * @return array<string, mixed>
     */
    private function reportArray(
        string $documentUuid,
        array $instances,
        array $events,
        bool $withContext,
        bool $withDiff,
        bool $withDecisions,
        array $diffsByEventKey,
        array $decisionChangesByEventKey,
        bool $withAccess,
        array $accessResults
    ): array {
        $report = [
            'documentUuid' => $documentUuid,
            'instances' => array_map(
                static fn (DocumentTimelineInstanceRow $row): array => $row->toArray(),
                $instances
            ),
            'events' => array_map(
                fn (DocumentTimelineEventRow $row): array => $this->eventArray($row, $withContext, $withDiff, $withDecisions, $diffsByEventKey, $decisionChangesByEventKey),
                $events
            ),
        ];

        if ($withAccess) {
            $report['accessResults'] = array_map(
                static fn (VisibilityCheckResultRecord $record): array => $record->toArray(),
                $accessResults
            );
        }

        return $report;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $diffsByEventKey
     * @param array<string, array<int, array<string, mixed>>> $decisionChangesByEventKey
     * @return array<string, mixed>
     */
    private function eventArray(
        DocumentTimelineEventRow $event,
        bool $withContext,
        bool $withDiff,
        bool $withDecisions,
        array $diffsByEventKey,
        array $decisionChangesByEventKey
    ): array {
        $row = $event->toArray();
        if ($withContext) {
            $row['context'] = $event->contextSummary['attributes'] ?? null;
            $row['contextWarnings'] = $event->contextSummary['warnings'] ?? [];
        }
        if ($withDiff) {
            $row['contextDiff'] = $diffsByEventKey[$event->externalEventKey] ?? null;
        }
        if ($withDecisions) {
            $row['ruleRelevantContextChanges'] = $decisionChangesByEventKey[$event->externalEventKey] ?? [];
        }

        return $row;
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     * @param array<string, array<int, array<string, mixed>>> $diffsByEventKey
     * @param array<string, array<int, array<string, mixed>>> $decisionChangesByEventKey
     */
    private function writeContextTimeline(
        OutputInterface $output,
        array $events,
        bool $withContext,
        bool $withDiff,
        bool $withDecisions,
        array $diffsByEventKey,
        array $decisionChangesByEventKey
    ): void {
        $output->writeln('');
        $output->writeln('<comment>Context Timeline</comment>');

        foreach ($events as $event) {
            $output->writeln('');
            $output->writeln(sprintf(
                '[%s] externalEventKey=%s eventKey=%s stepKey=%s documentVersion=%d',
                $event->occurredAt->format('Y-m-d H:i:s'),
                $event->externalEventKey,
                $event->eventKey,
                $event->stepKey,
                $event->documentVersion
            ));

            if ($withContext) {
                $context = $event->contextSummary['attributes'] ?? null;
                if (!is_array($context)) {
                    $output->writeln('Context Snapshot: none');
                } else {
                    $output->writeln('Context Snapshot:');
                    foreach ($context as $field => $value) {
                        $output->writeln(sprintf('- %s: %s', $field, $this->formatValue($value)));
                    }
                }
            }

            if ($withDiff) {
                $output->writeln('Context Diff:');
                $this->writeDiffLines($output, $diffsByEventKey[$event->externalEventKey] ?? null);
            }

            if ($withDecisions && ($decisionChangesByEventKey[$event->externalEventKey] ?? []) !== []) {
                $output->writeln('Rule-relevant context change:');
                foreach ($decisionChangesByEventKey[$event->externalEventKey] as $change) {
                    $output->writeln(sprintf(
                        '- %s %s from %s to %s',
                        (string) ($change['field'] ?? ''),
                        (string) ($change['type'] ?? 'changed'),
                        $this->formatValue($change['from'] ?? null),
                        $this->formatValue($change['to'] ?? null)
                    ));
                    $output->writeln(sprintf('  affected decisions: %s', implode(', ', $change['affected_decisions'] ?? [])));
                }
            }
        }
    }

    /**
     * @param array<int, VisibilityCheckResultRecord> $accessResults
     */
    private function writeAccessTimeline(OutputInterface $output, array $accessResults): void
    {
        $output->writeln('');
        $output->writeln('<comment>Sichtbarkeitspruefungen</comment>');

        if ($accessResults === []) {
            $output->writeln('- keine gespeicherten Ergebnisse gefunden');

            return;
        }

        foreach ($this->groupAccessResults($accessResults) as $group) {
            /** @var VisibilityCheckResultRecord $first */
            $first = $group[0];
            $output->writeln(sprintf(
                '- stepKey=%s eventPhase=%s checkKey=%s checkedAt=%s',
                $first->stepKey,
                $first->eventPhase,
                $first->checkKey,
                $first->checkedAt->format('Y-m-d H:i:s')
            ));

            foreach ($group as $record) {
                $reason = $record->reason === null ? '' : sprintf(' reason=%s', $record->reason);
                $output->writeln(sprintf(
                    '  - %s expected=%s actual=%s status=%s%s',
                    $record->probeKey,
                    $record->expected,
                    $record->actual,
                    $record->status,
                    $reason
                ));
            }
        }
    }

    /**
     * @param array<int, VisibilityCheckResultRecord> $records
     * @return array<string, array<int, VisibilityCheckResultRecord>>
     */
    private function groupAccessResults(array $records): array
    {
        $groups = [];
        foreach ($records as $record) {
            $key = implode('|', [
                $record->stepKey,
                $record->eventPhase,
                $record->checkKey,
                $record->checkedAt->format(DATE_ATOM),
            ]);
            $groups[$key][] = $record;
        }

        return $groups;
    }

    /**
     * @param array<int, array<string, mixed>>|null $diffs
     */
    private function writeDiffLines(OutputInterface $output, ?array $diffs): void
    {
        if ($diffs === null) {
            $output->writeln('- no context snapshot');
            return;
        }
        if ($diffs === []) {
            $output->writeln('- no changes');
            return;
        }

        foreach ($diffs as $diff) {
            $output->writeln(sprintf(
                '- %s: %s -> %s (%s)',
                (string) ($diff['field'] ?? ''),
                $this->formatValue($diff['from'] ?? null),
                $this->formatValue($diff['to'] ?? null),
                (string) ($diff['type'] ?? 'changed')
            ));
        }
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     */
    private function templateFor(?string $processKey, array $events): ?ProcessTemplate
    {
        if ($this->templateProvider === null) {
            return null;
        }

        $processKey ??= $this->singleProcessKey($events);
        if ($processKey === null) {
            return null;
        }

        return $this->templateProvider->findByProcessKey($processKey);
    }

    /**
     * @param array<int, DocumentTimelineEventRow> $events
     */
    private function singleProcessKey(array $events): ?string
    {
        $keys = [];
        foreach ($events as $event) {
            $keys[$event->processKey] = true;
        }

        return count($keys) === 1 ? (string) array_key_first($keys) : null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function decisionFields(ProcessTemplate $template): array
    {
        $fields = [];
        foreach ($template->decisionPoints as $decisionPoint) {
            foreach ($decisionPoint->requiredFields as $field) {
                $fields[$field][$decisionPoint->key] = $decisionPoint->key;
            }
            foreach ($decisionPoint->rules as $rule) {
                if ($rule->condition !== null) {
                    $fields[$rule->condition->field][$decisionPoint->key] = $decisionPoint->key;
                }
            }
        }

        return array_map(static fn (array $decisions): array => array_values($decisions), $fields);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $diffsByEventKey
     * @param array<string, array<int, string>> $decisionFields
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function decisionChanges(array $diffsByEventKey, array $decisionFields): array
    {
        $changesByEventKey = [];
        foreach ($diffsByEventKey as $eventKey => $diffs) {
            foreach ($diffs as $diff) {
                $field = $diff['field'] ?? null;
                if (!is_string($field) || !isset($decisionFields[$field])) {
                    continue;
                }

                $diff['affected_decisions'] = $decisionFields[$field];
                $changesByEventKey[$eventKey][] = $diff;
            }
        }

        return $changesByEventKey;
    }

    /**
     * @param array<string, mixed>|null $contextSummary
     */
    private function contextSummary(?array $contextSummary): string
    {
        if ($contextSummary === null) {
            return '';
        }

        $fields = implode(',', $contextSummary['fields'] ?? []);
        $warningCount = count($contextSummary['warnings'] ?? []);

        return $warningCount > 0 ? sprintf('%s (%d Warnung(en))', $fields, $warningCount) : $fields;
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
