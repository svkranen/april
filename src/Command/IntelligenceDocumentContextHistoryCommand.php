<?php

namespace App\Command;

use App\Intelligence\Application\ContextDiffBuilder;
use App\Intelligence\Application\ContextDiffReport;
use App\Intelligence\Application\ContextHistoryBuilder;
use App\Intelligence\Application\ContextHistoryEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:document:context-history',
    description: 'Shows context snapshot history and optional context diffs for one document.'
)]
final class IntelligenceDocumentContextHistoryCommand extends Command
{
    public function __construct(
        private readonly ContextHistoryBuilder $historyBuilder,
        private readonly ContextDiffBuilder $diffBuilder = new ContextDiffBuilder()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('documentUuid', InputArgument::REQUIRED, 'Document UUID')
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process key')
            ->addOption('diff', null, InputOption::VALUE_NONE, 'Show field diff summary')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Comma-separated context fields to include')
            ->addOption('with-empty', null, InputOption::VALUE_NONE, 'Include null, empty string, and empty array values');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $documentUuid = (string) $input->getArgument('documentUuid');
        $processKey = (string) $input->getArgument('processKey');
        $history = $this->historyBuilder->build(
            $documentUuid,
            $processKey,
            $this->fields($input->getOption('fields')),
            $input->getOption('with-empty') === true
        );
        $diff = $input->getOption('diff') === true ? $this->diffBuilder->build($history) : null;

        if ($input->getOption('json') === true) {
            $data = $history->toArray();
            if ($diff !== null) {
                $data['diff'] = $diff->toArray();
            }
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Context History for document %s / %s', $documentUuid, $processKey));
        if ($history->entries === []) {
            $output->writeln('No context snapshots found.');

            return Command::SUCCESS;
        }

        foreach ($history->entries as $entry) {
            $this->writeEntry($output, $entry);
        }

        if ($diff !== null) {
            $this->writeDiff($output, $diff);
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<int, string>|null
     */
    private function fields(mixed $value): ?array
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    private function writeEntry(OutputInterface $output, ContextHistoryEntry $entry): void
    {
        $output->writeln('');
        $output->writeln(sprintf('[%s] externalEventKey=%s', $entry->at->format('Y-m-d H:i:s'), $entry->externalEventKey ?? ''));
        if ($entry->eventKey !== null || $entry->stepKey !== null) {
            $output->writeln(sprintf('eventKey: %s', $entry->eventKey ?? ''));
            $output->writeln(sprintf('stepKey: %s', $entry->stepKey ?? ''));
        }
        $output->writeln(sprintf('documentVersion: %d', $entry->documentVersion));
        foreach ($entry->contextJson as $field => $value) {
            $output->writeln(sprintf('%s: %s', $field, $this->formatValue($value)));
        }
        if ($entry->warnings !== []) {
            $output->writeln(sprintf('warnings: %s', implode('; ', $entry->warnings)));
        }
    }

    private function writeDiff(OutputInterface $output, ContextDiffReport $diff): void
    {
        $output->writeln('');
        $output->writeln('Changed fields:');
        $this->writeChangedFields($output, $diff->changedFields);
        $output->writeln('');
        $output->writeln('Added fields:');
        $this->writeSingleValueChanges($output, $diff->addedFields, 'value');
        $output->writeln('');
        $output->writeln('Removed fields:');
        $this->writeSingleValueChanges($output, $diff->removedFields, 'value');
        $output->writeln('');
        $output->writeln('Unchanged fields:');
        if ($diff->unchangedFields === []) {
            $output->writeln('- none');
        } else {
            foreach ($diff->unchangedFields as $field => $value) {
                $output->writeln(sprintf('- %s: %s', $field, $this->formatValue($value)));
            }
        }
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $fields
     */
    private function writeChangedFields(OutputInterface $output, array $fields): void
    {
        if ($fields === []) {
            $output->writeln('- none');
            return;
        }

        foreach ($fields as $field => $changes) {
            $values = [];
            foreach ($changes as $index => $change) {
                if ($index === 0) {
                    $values[] = $this->formatValue($change['from'] ?? null);
                }
                $values[] = $this->formatValue($change['to'] ?? null);
            }
            $output->writeln(sprintf('- %s: %s', $field, implode(' -> ', $values)));
        }
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $fields
     */
    private function writeSingleValueChanges(OutputInterface $output, array $fields, string $valueKey): void
    {
        if ($fields === []) {
            $output->writeln('- none');
            return;
        }

        foreach ($fields as $field => $changes) {
            $last = $changes[count($changes) - 1] ?? [];
            $output->writeln(sprintf('- %s: %s', $field, $this->formatValue($last[$valueKey] ?? null)));
        }
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
