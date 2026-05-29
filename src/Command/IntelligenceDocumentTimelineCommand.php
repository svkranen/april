<?php

namespace App\Command;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineInstanceRow;
use App\Intelligence\Application\DocumentTimelineProvider;
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
        private readonly DocumentTimelineProvider $timelineProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('documentUuid', InputArgument::REQUIRED, 'Document UUID to report')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $documentUuid = (string) $input->getArgument('documentUuid');
        $format = (string) $input->getOption('format');

        if (!in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use "table" or "json".</error>');

            return Command::INVALID;
        }

        $report = $this->timelineProvider->build($documentUuid);

        if ($format === 'json') {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Dokument-UUID:</info> %s', $report->documentUuid));
        if ($report->isEmpty()) {
            $output->writeln('<comment>Keine Prozessinstanzen oder Events fuer dieses Dokument gefunden.</comment>');

            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln('<comment>Prozessinstanzen je Version</comment>');
        $instanceTable = new Table($output);
        $instanceTable->setHeaders(['ID', 'processKey', 'Dokumentversion', 'currentStepKey', 'Status']);
        foreach ($report->instances as $instance) {
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
            'processKey',
            'Dokumentversion',
            'occurredAt',
            'processInstanceId',
            'duplicate',
            'Context Snapshot',
        ]);
        foreach ($report->events as $event) {
            $eventTable->addRow($this->eventRow($event));
        }
        $eventTable->render();

        return Command::SUCCESS;
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
            $event->processKey,
            $event->documentVersion,
            $event->occurredAt->format(DATE_ATOM),
            $event->processInstanceId ?? '',
            $event->duplicate ? 'yes' : 'no',
            $this->contextSummary($event->contextSummary),
        ];
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
}
