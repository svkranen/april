<?php

namespace App\Command;

use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\ProcessDocumentRef;
use App\Intelligence\Application\ProcessDocumentUuidProvider;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use JsonException;
use Throwable;
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
            ->addOption('show-ok', null, InputOption::VALUE_NONE, 'Also list documents without warnings, deviations, or errors')
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
        $documentRefs = $this->documentUuidProvider->documentRefsForProcess($processKey);

        $rows = [];
        foreach ($documentRefs as $documentRef) {
            try {
                $result = $this->checkService->checkDocument($documentRef->documentUuid, $processKey, $template, $documentVersion, $order);
                $rows[] = $this->row($documentRef, $result);
            } catch (Throwable $exception) {
                $rows[] = $this->errorRow($documentRef, $exception);
            }
        }

        $statusCounts = $this->statusCounts($rows);
        $visibleRows = $this->visibleRows(
            $rows,
            $input->getOption('show-ok') === true,
            $input->getOption('only-deviations') === true
        );

        $report = [
            'process_key' => $processKey,
            'template_key' => $template->key,
            'total_documents' => count($documentRefs),
            'ok_count' => $statusCounts['OK'],
            'warning_count' => $statusCounts['WARNING'],
            'deviation_count' => $statusCounts['DEVIATION'],
            'error_count' => $statusCounts['ERROR'],
            'status_counts' => $statusCounts,
            'deviation_summary' => $this->problemSummary($rows, 'deviations'),
            'warning_summary' => $this->problemSummary($rows, 'warnings'),
            'top_problem_documents' => $this->topProblemDocuments($rows),
            'documents' => $visibleRows,
            'groups' => $this->groupRows($visibleRows),
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
     * @return array{documentId: string, documentUuid: string, documentVersion: int|null, status: string, problem_score: int, warning_count: int, deviation_count: int, error: string|null, warnings: array<int, string>, deviations: array<int, string>}
     */
    private function row(ProcessDocumentRef $documentRef, ProcessTemplateCheckResult $result): array
    {
        $status = $this->statusForResult($result);
        $messages = $this->normalizedMessages($result->deviations, 'Unknown deviation');
        $warnings = $status === 'WARNING' ? $messages : [];
        $deviations = $status === 'DEVIATION' ? $messages : [];

        return [
            'documentId' => $documentRef->documentExternalId ?? '<unknown>',
            'documentUuid' => $documentRef->documentUuid,
            'documentVersion' => $documentRef->documentVersion,
            'status' => $status,
            'problem_score' => $this->problemScore($deviations, $warnings, null),
            'warning_count' => $status === 'WARNING' ? count($result->deviations) : 0,
            'deviation_count' => $status === 'DEVIATION' ? count($result->deviations) : 0,
            'error' => null,
            'warnings' => $warnings,
            'deviations' => $deviations,
        ];
    }

    /**
     * @return array{documentId: string, documentUuid: string, documentVersion: int|null, status: string, problem_score: int, warning_count: int, deviation_count: int, error: string, warnings: array<int, string>, deviations: array<int, string>}
     */
    private function errorRow(ProcessDocumentRef $documentRef, Throwable $exception): array
    {
        return [
            'documentId' => $documentRef->documentExternalId ?? '<unknown>',
            'documentUuid' => $documentRef->documentUuid,
            'documentVersion' => $documentRef->documentVersion,
            'status' => 'ERROR',
            'problem_score' => $this->problemScore([], [], $exception->getMessage()),
            'warning_count' => 0,
            'deviation_count' => 0,
            'error' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Unknown error',
            'warnings' => [],
            'deviations' => [],
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
        $output->writeln(sprintf('warning_count: %d', $report['warning_count']));
        $output->writeln(sprintf('deviation_count: %d', $report['deviation_count']));
        $output->writeln(sprintf('error_count: %d', $report['error_count']));
        $this->renderSummary('Deviation Summary', $report['deviation_summary'], $output);
        $this->renderSummary('Warning Summary', $report['warning_summary'], $output);
        $this->renderTopProblemDocuments($report['top_problem_documents'], $output);

        foreach (['WARNING', 'DEVIATION', 'ERROR', 'OK'] as $status) {
            $rows = $report['groups'][$status] ?? [];
            if ($rows === []) {
                continue;
            }

            $output->writeln($status.':');
            foreach ($rows as $row) {
                $output->writeln(sprintf(
                    '  - documentId: %s; documentUuid: %s; status: %s; score: %d; warnings: %d; deviations: %d',
                    $row['documentId'],
                    $row['documentUuid'],
                    $row['status'],
                    $row['problem_score'],
                    $row['warning_count'],
                    $row['deviation_count']
                ));
                foreach ($row['warnings'] as $warning) {
                    $output->writeln(sprintf('      - %s', $warning));
                }
                foreach ($row['deviations'] as $deviation) {
                    $output->writeln(sprintf('      - %s', $deviation));
                }
                if ($row['error'] !== null) {
                    $output->writeln(sprintf('      - %s', $row['error']));
                }
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    private function renderTopProblemDocuments(array $documents, OutputInterface $output): void
    {
        if ($documents === []) {
            return;
        }

        $output->writeln('Top Problem Documents:');
        foreach ($documents as $index => $document) {
            $output->writeln(sprintf('%d. documentId: %s', $index + 1, $document['documentId']));
            $output->writeln(sprintf('   score: %d', $document['problem_score']));
            $output->writeln(sprintf('   deviations: %d', $document['deviation_count']));
        }
    }

    /**
     * @param array<string, int> $summary
     */
    private function renderSummary(string $title, array $summary, OutputInterface $output): void
    {
        if ($summary === []) {
            return;
        }

        $output->writeln($title.':');
        foreach ($summary as $label => $count) {
            $output->writeln(sprintf('  - %s: %d', $label, $count));
        }
    }

    private function statusForResult(ProcessTemplateCheckResult $result): string
    {
        if ($result->deviations === []) {
            return 'OK';
        }

        foreach ($result->deviations as $deviation) {
            if (!$this->isWarningMessage($this->messageToString($deviation))) {
                return 'DEVIATION';
            }
        }

        return 'WARNING';
    }

    private function isWarningMessage(string $message): bool
    {
        return str_starts_with($message, 'Missing context ')
            || str_starts_with($message, 'Missing required context field ')
            || str_starts_with($message, 'Unknown Amagno tag_name ')
            || str_starts_with($message, 'Ambiguous Amagno tag_name ')
            || str_starts_with($message, 'No context available ');
    }

    /**
     * @param array<int, mixed> $messages
     * @return array<int, string>
     */
    private function normalizedMessages(array $messages, string $fallback): array
    {
        return array_map(
            function (mixed $message) use ($fallback): string {
                $normalized = $this->messageToString($message);

                return $normalized !== '' ? $normalized : $fallback;
            },
            $messages
        );
    }

    private function messageToString(mixed $message): string
    {
        if (is_string($message)) {
            return trim($message);
        }

        if (is_scalar($message) || $message instanceof \Stringable) {
            return trim((string) $message);
        }

        return '';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    private function problemSummary(array $rows, string $field): array
    {
        $summary = [];
        foreach ($rows as $row) {
            foreach ($row[$field] ?? [] as $message) {
                $label = $this->summaryLabel((string) $message);
                $summary[$label] = ($summary[$label] ?? 0) + 1;
            }
        }

        arsort($summary);

        return $summary;
    }

    private function summaryLabel(string $message): string
    {
        if (preg_match('/^Missing context for decision point ([^:]+):/', $message, $matches) === 1) {
            return 'Missing context '.$matches[1];
        }

        foreach ([
            'Decision rule violation',
            'Missing step',
            'Unexpected step',
            'Parallel Group incomplete',
            'Wrong order',
            'Unknown deviation',
        ] as $prefix) {
            if (str_starts_with($message, $prefix)) {
                return $prefix;
            }
        }

        $separatorPosition = strpos($message, ':');
        if ($separatorPosition !== false) {
            return substr($message, 0, $separatorPosition);
        }

        return $message !== '' ? $message : 'Unknown deviation';
    }

    /**
     * @param array<int, string> $deviations
     * @param array<int, string> $warnings
     */
    private function problemScore(array $deviations, array $warnings, ?string $error): int
    {
        if ($error !== null) {
            return 5;
        }

        $score = 0;
        foreach ($deviations as $deviation) {
            $score += match ($this->summaryLabel($deviation)) {
                'Missing step' => 3,
                'Decision rule violation', 'Parallel Group incomplete' => 2,
                default => 1,
            };
        }

        foreach ($warnings as $warning) {
            $score += str_starts_with($this->summaryLabel($warning), 'Missing context ') ? 0 : 1;
        }

        return $score;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function topProblemDocuments(array $rows): array
    {
        $documents = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['problem_score'] ?? 0) > 0
        ));

        usort(
            $documents,
            static fn (array $left, array $right): int => [
                -$left['problem_score'],
                -($left['deviation_count'] + $left['warning_count'] + ($left['status'] === 'ERROR' ? 1 : 0)),
                (string) $left['documentId'],
            ] <=> [
                -$right['problem_score'],
                -($right['deviation_count'] + $right['warning_count'] + ($right['status'] === 'ERROR' ? 1 : 0)),
                (string) $right['documentId'],
            ]
        );

        return $documents;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{OK: int, WARNING: int, DEVIATION: int, ERROR: int}
     */
    private function statusCounts(array $rows): array
    {
        $counts = ['OK' => 0, 'WARNING' => 0, 'DEVIATION' => 0, 'ERROR' => 0];
        foreach ($rows as $row) {
            ++$counts[$row['status']];
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function visibleRows(array $rows, bool $showOk, bool $onlyDeviations): array
    {
        return array_values(array_filter(
            $rows,
            static function (array $row) use ($showOk, $onlyDeviations): bool {
                if ($onlyDeviations) {
                    return $row['status'] === 'DEVIATION';
                }

                return $showOk || $row['status'] !== 'OK';
            }
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupRows(array $rows): array
    {
        $groups = ['OK' => [], 'WARNING' => [], 'DEVIATION' => [], 'ERROR' => []];
        foreach ($rows as $row) {
            $groups[$row['status']][] = $row;
        }

        return $groups;
    }
}
