<?php

namespace App\Command;

use App\Intelligence\Application\VisibilityCheckResultProvider;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:access:results',
    description: 'Lists persisted access visibility check results for a document.'
)]
final class IntelligenceAccessResultsCommand extends Command
{
    public function __construct(
        private readonly VisibilityCheckResultProvider $resultProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('documentUuid', InputArgument::REQUIRED, 'Document UUID')
            ->addArgument('processKey', InputArgument::OPTIONAL, 'Optional process key')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    /**
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use one of: text, json.</error>');

            return Command::INVALID;
        }

        $documentUuid = (string) $input->getArgument('documentUuid');
        $processKey = $input->getArgument('processKey');
        $processKey = is_scalar($processKey) && trim((string) $processKey) !== '' ? trim((string) $processKey) : null;
        $records = $this->resultProvider->findByDocument($documentUuid, $processKey);

        if ($format === 'json') {
            $output->writeln(json_encode([
                'documentUuid' => $documentUuid,
                'processKey' => $processKey,
                'results' => array_map(static fn ($record): array => $record->toArray(), $records),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['checkedAt', 'processKey', 'stepKey', 'eventPhase', 'checkKey', 'profileKey', 'probeKey', 'expected', 'actual', 'status', 'reason']);
        foreach ($records as $record) {
            $table->addRow([
                $record->checkedAt->format(DATE_ATOM),
                $record->processKey,
                $record->stepKey,
                $record->eventPhase,
                $record->checkKey,
                $record->profileKey ?? '',
                $record->probeKey,
                $record->expected,
                $record->actual,
                $record->status,
                $record->reason ?? '',
            ]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
