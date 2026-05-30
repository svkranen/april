<?php

namespace App\Command;

use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessDocumentUuidProvider;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'intelligence:template:check-process',
    description: 'Checks all document timelines of a process against a YAML process template.'
)]
final class IntelligenceTemplateCheckProcessCommand extends Command
{
    public function __construct(
        private readonly ProcessTemplateCheckService $checkService,
        private readonly ProcessDocumentUuidProvider $documentUuidProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process key to check')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Path to the YAML process template')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text')
            ->addOption('only-deviations', null, InputOption::VALUE_NONE, 'Only list documents with deviations')
            ->addOption('document-version', null, InputOption::VALUE_REQUIRED, 'Document version to check for every document')
            ->addOption('order-by', null, InputOption::VALUE_REQUIRED, 'Event order: occurred-at, received-at, or occurred-then-received', EventTimelineOrder::DEFAULT->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processKey = (string) $input->getArgument('processKey');
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use text or json.</error>');

            return Command::INVALID;
        }

        $templatePath = $input->getOption('template');
        if ($templatePath === null || !is_string($templatePath) || $templatePath === '') {
            $output->writeln('<error>Missing required --template option.</error>');

            return Command::INVALID;
        }

        if (!is_file($templatePath)) {
            $output->writeln(sprintf('<error>Template file not found: %s</error>', $templatePath));

            return Command::FAILURE;
        }

        $templateData = Yaml::parseFile($templatePath);
        if (!is_array($templateData)) {
            $output->writeln(sprintf('<error>Template file is not a YAML mapping: %s</error>', $templatePath));

            return Command::FAILURE;
        }
        $template = ProcessTemplateArrayFactory::fromArray($templateData);

        $order = EventTimelineOrder::fromOption((string) $input->getOption('order-by'));
        if ($order === null) {
            $output->writeln(sprintf('<error>Invalid --order-by. Use one of: %s.</error>', implode(', ', EventTimelineOrder::values())));

            return Command::INVALID;
        }

        $versionOption = $input->getOption('document-version');
        $documentVersion = $versionOption === null ? null : (int) $versionOption;
        $documentUuids = $this->documentUuidProvider->documentUuidsForProcess($processKey);

        $rows = [];
        $okCount = 0;
        $deviationCount = 0;
        foreach ($documentUuids as $documentUuid) {
            $result = $this->checkService->checkDocument($documentUuid, $processKey, $template, $documentVersion, $order);
            if ($result->isOk()) {
                ++$okCount;
            } else {
                ++$deviationCount;
            }

            $rows[] = $this->row($documentUuid, $result);
        }

        if ($input->getOption('only-deviations') === true) {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['status'] !== 'OK'
            ));
        }

        $report = [
            'process_key' => $processKey,
            'template_key' => $template->key,
            'total_documents' => count($documentUuids),
            'ok_count' => $okCount,
            'deviation_count' => $deviationCount,
            'warning_count' => 0,
            'documents' => $rows,
        ];

        if ($format === 'json') {
            try {
                $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $output->writeln(sprintf('<error>Could not encode report: %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $this->renderText($report, $output);

        return Command::SUCCESS;
    }

    /**
     * @return array{documentUuid: string, status: string, deviation_count: int, deviations: array<int, string>}
     */
    private function row(string $documentUuid, ProcessTemplateCheckResult $result): array
    {
        return [
            'documentUuid' => $documentUuid,
            'status' => $result->status(),
            'deviation_count' => count($result->deviations),
            'deviations' => array_slice($result->deviations, 0, 5),
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderText(array $report, OutputInterface $output): void
    {
        $output->writeln(sprintf('process_key: %s', $report['process_key']));
        $output->writeln(sprintf('template_key: %s', $report['template_key']));
        $output->writeln(sprintf('total_documents: %d', $report['total_documents']));
        $output->writeln(sprintf('ok_count: %d', $report['ok_count']));
        $output->writeln(sprintf('deviation_count: %d', $report['deviation_count']));
        $output->writeln(sprintf('warning_count: %d', $report['warning_count']));
        $output->writeln('documents:');

        foreach ($report['documents'] as $row) {
            $output->writeln(sprintf(
                '  - documentUuid: %s; status: %s; deviations: %d',
                $row['documentUuid'],
                $row['status'],
                $row['deviation_count']
            ));
            foreach ($row['deviations'] as $deviation) {
                $output->writeln(sprintf('      - %s', $deviation));
            }
        }
    }
}
