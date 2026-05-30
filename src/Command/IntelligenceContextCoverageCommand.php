<?php

namespace App\Command;

use App\Intelligence\Application\ContextCoverageFieldRow;
use App\Intelligence\Application\ContextCoverageReportProvider;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:context:coverage',
    description: 'Shows context field coverage for a process key.'
)]
final class IntelligenceContextCoverageCommand extends Command
{
    public function __construct(
        private readonly ContextCoverageReportProvider $reportProvider
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
            try {
                $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $output->writeln(sprintf('<error>Could not encode report: %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Process:</info> %s', $report->processKey));
        $output->writeln(sprintf('<info>Context Snapshots:</info> %d', $report->snapshotCount));
        $output->writeln('');

        $table = new Table($output);
        $table->setHeaders(['fieldKey', 'coverage', 'observedTypes', 'exampleValues']);
        foreach ($report->fields as $field) {
            $table->addRow($this->fieldRow($field));
        }
        $table->render();

        return Command::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function fieldRow(ContextCoverageFieldRow $field): array
    {
        return [
            $field->fieldKey,
            sprintf('%.2f%% (%d present, %d missing)', $field->coverage * 100, $field->presentCount, $field->missingCount),
            implode(', ', $field->observedTypes),
            implode(', ', array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value), $field->exampleValues)),
        ];
    }
}
