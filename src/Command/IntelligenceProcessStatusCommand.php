<?php

namespace App\Command;

use App\Intelligence\Application\ProcessStatusEventRow;
use App\Intelligence\Application\ProcessStatusInstanceRow;
use App\Intelligence\Application\ProcessStatusReportProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:process:status',
    description: 'Shows the current status of process instances for a process key.'
)]
final class IntelligenceProcessStatusCommand extends Command
{
    public function __construct(
        private readonly ProcessStatusReportProvider $reportProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process key to report')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processKey = (string) $input->getArgument('processKey');
        $format = (string) $input->getOption('format');

        if (!in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use "table" or "json".</error>');

            return Command::INVALID;
        }

        $report = $this->reportProvider->build($processKey);

        if ($format === 'json') {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Process:</info> %s', $report->processKey));
        $output->writeln(sprintf('<info>Prozessinstanzen gesamt:</info> %d', $report->totalInstances));
        $output->writeln('');

        $output->writeln('<comment>Anzahl je currentStepKey</comment>');
        $stepTable = new Table($output);
        $stepTable->setHeaders(['currentStepKey', 'Anzahl']);
        foreach ($report->countsByStep as $stepKey => $count) {
            $stepTable->addRow([$stepKey, $count]);
        }
        $stepTable->render();
        $output->writeln('');

        $output->writeln('<comment>Offene Prozessinstanzen</comment>');
        $instanceTable = new Table($output);
        $instanceTable->setHeaders(['ID', 'Dokument-UUID', 'Dokumentversion', 'currentStepKey', 'lastEventAt', 'Status']);
        foreach ($report->openInstances as $instance) {
            $instanceTable->addRow($this->instanceRow($instance));
        }
        $instanceTable->render();
        $output->writeln('');

        $output->writeln('<comment>Letzte Events</comment>');
        $eventTable = new Table($output);
        $eventTable->setHeaders(['externalEventKey', 'Dokument-UUID', 'Dokumentversion', 'stepKey', 'occurredAt']);
        foreach ($report->latestEvents as $event) {
            $eventTable->addRow($this->eventRow($event));
        }
        $eventTable->render();

        return Command::SUCCESS;
    }

    /**
     * @return array<int, mixed>
     */
    private function instanceRow(ProcessStatusInstanceRow $instance): array
    {
        return [
            $instance->id ?? '',
            $instance->documentUuid ?? '',
            $instance->documentVersion,
            $instance->currentStepKey,
            $instance->lastEventAt->format(DATE_ATOM),
            $instance->status,
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function eventRow(ProcessStatusEventRow $event): array
    {
        return [
            $event->externalEventKey,
            $event->documentUuid ?? '',
            $event->documentVersion,
            $event->stepKey,
            $event->occurredAt->format(DATE_ATOM),
        ];
    }
}
