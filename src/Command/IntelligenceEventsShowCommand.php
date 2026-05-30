<?php

namespace App\Command;

use App\Intelligence\Application\EventDetails;
use App\Intelligence\Application\EventDetailsProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:events:show',
    description: 'Shows details for a received intelligence event.'
)]
final class IntelligenceEventsShowCommand extends Command
{
    public function __construct(
        private readonly EventDetailsProvider $eventDetailsProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('eventId', InputArgument::REQUIRED, 'Event ID to inspect')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Show raw payload JSON')
            ->addOption('normalized', null, InputOption::VALUE_NONE, 'Show normalized event JSON')
            ->addOption('context', null, InputOption::VALUE_NONE, 'Show context snapshot JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $event = $this->eventDetailsProvider->find((int) $input->getArgument('eventId'));
        if ($event === null) {
            $output->writeln(sprintf('<error>Event not found: %d</error>', (int) $input->getArgument('eventId')));

            return Command::FAILURE;
        }

        $this->renderBaseData($event, $output);

        if ($input->getOption('raw') === true) {
            $this->renderJsonSection('Raw Payload', $event->rawPayload, $output);
        }

        if ($input->getOption('normalized') === true) {
            $this->renderJsonSection('Normalized Event', $event->normalizedEvent, $output);
        }

        if ($input->getOption('context') === true) {
            $this->renderJsonSection('Context Snapshot', $event->contextSnapshotsArray(), $output);
        }

        return Command::SUCCESS;
    }

    private function renderBaseData(EventDetails $event, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setHeaders(['Field', 'Value']);

        foreach ($event->baseData() as $field => $value) {
            $table->addRow([$field, $value ?? '']);
        }

        $table->render();
    }

    /**
     * @param array<mixed> $data
     */
    private function renderJsonSection(string $title, array $data, OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln(sprintf('<comment>%s</comment>', $title));
        $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
