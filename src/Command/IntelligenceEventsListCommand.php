<?php

namespace App\Command;

use App\Intelligence\Application\EventListFilter;
use App\Intelligence\Application\EventListProvider;
use App\Intelligence\Application\EventListRow;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:events:list',
    description: 'Lists received intelligence events for debugging.'
)]
final class IntelligenceEventsListCommand extends Command
{
    public function __construct(
        private readonly EventListProvider $eventListProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of events to show', '20')
            ->addOption('process-key', null, InputOption::VALUE_REQUIRED, 'Filter by process key')
            ->addOption('document-uuid', null, InputOption::VALUE_REQUIRED, 'Filter by document UUID')
            ->addOption('document-id', null, InputOption::VALUE_REQUIRED, 'Filter by document external ID')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Filter events received or occurred since this datetime')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['table', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use "table" or "json".</error>');

            return Command::INVALID;
        }

        $limit = max(1, (int) $input->getOption('limit'));
        $since = $input->getOption('since');
        $filter = new EventListFilter(
            $limit,
            $this->optionalString($input->getOption('process-key')),
            $this->optionalString($input->getOption('document-uuid')),
            $this->optionalString($input->getOption('document-id')),
            $since === null ? null : new DateTimeImmutable((string) $since)
        );

        $events = $this->eventListProvider->list($filter);

        if ($format === 'json') {
            $output->writeln(json_encode(array_map(
                static fn (EventListRow $event): array => $event->toArray(),
                $events
            ), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        if ($events === []) {
            $output->writeln('<comment>No events found.</comment>');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders([
            'id',
            'externalEventKey',
            'processKey',
            'eventKey',
            'stepKey',
            'documentExternalId',
            'documentUuid',
            'documentVersion',
            'processInstanceId',
            'occurredAt',
            'receivedAt',
            'duplicate',
        ]);

        foreach ($events as $event) {
            $table->addRow($this->eventRow($event));
        }

        $table->render();

        return Command::SUCCESS;
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<int, mixed>
     */
    private function eventRow(EventListRow $event): array
    {
        return [
            $event->id ?? '',
            $event->externalEventKey,
            $event->processKey,
            $event->eventKey,
            $event->stepKey,
            $event->documentExternalId,
            $event->documentUuid ?? '',
            $event->documentVersion,
            $event->processInstanceId ?? '',
            $event->occurredAt->format(DATE_ATOM),
            $event->receivedAt->format(DATE_ATOM),
            $event->duplicate ? 'yes' : 'no',
        ];
    }
}
