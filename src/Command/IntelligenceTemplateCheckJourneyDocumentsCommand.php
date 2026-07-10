<?php

namespace App\Command;

use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\JourneyDocumentCheckReport;
use App\Intelligence\Application\JourneyDocumentCheckRow;
use App\Intelligence\Application\JourneyDocumentCheckService;
use App\Intelligence\Application\JourneyTemplateCheckService;
use App\Intelligence\Application\JourneyTemplateStepCheckResult;
use App\Intelligence\Application\JourneyTemplateTransitionCheckResult;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\UnexpectedProcessResult;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:template:check-journey-documents',
    description: 'Finds journey candidate documents via match.any_process and checks them read-only.'
)]
final class IntelligenceTemplateCheckJourneyDocumentsCommand extends Command
{
    public function __construct(
        private readonly JourneyDocumentCheckService $checkService,
        private readonly ProcessTemplateProvider $templateProvider
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('journeyKey', InputArgument::REQUIRED, 'Journey template key to check')
            ->addOption('document-version', null, InputOption::VALUE_REQUIRED, 'Document version to check for every document')
            ->addOption('order-by', null, InputOption::VALUE_REQUIRED, 'Event order: occurred-at, received-at, or occurred-then-received', EventTimelineOrder::DEFAULT->value)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of candidate documents to check')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use text or json.</error>');

            return Command::INVALID;
        }

        $order = EventTimelineOrder::fromOption((string) $input->getOption('order-by'));
        if ($order === null) {
            $output->writeln(sprintf('<error>Invalid --order-by. Use one of: %s.</error>', implode(', ', EventTimelineOrder::values())));

            return Command::INVALID;
        }

        $journeyKey = (string) $input->getArgument('journeyKey');
        $template = $this->templateProvider->findByProcessKey($journeyKey);
        if ($template === null) {
            $output->writeln(sprintf('<error>Journey template not found: %s</error>', $journeyKey));

            return Command::FAILURE;
        }

        if ($template->scope !== 'journey') {
            $output->writeln(sprintf('<error>Template "%s" has scope "%s"; expected "journey".</error>', $template->key, $template->scope));

            return Command::INVALID;
        }

        $versionOption = $input->getOption('document-version');
        $documentVersion = $versionOption === null ? null : (int) $versionOption;
        $limitOption = $input->getOption('limit');
        $limit = $limitOption === null ? null : (int) $limitOption;
        if ($limit !== null && $limit < 1) {
            $output->writeln('<error>Option --limit must be greater than 0.</error>');

            return Command::INVALID;
        }

        $report = $this->checkService->checkDocuments($template, $documentVersion, $order, $limit);

        if ($format === 'json') {
            try {
                $output->writeln(json_encode($this->reportToArray($report), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } catch (JsonException $exception) {
                $output->writeln(sprintf('<error>Could not encode report: %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        $this->renderText($report, $output);

        return Command::SUCCESS;
    }

    private function renderText(JourneyDocumentCheckReport $report, OutputInterface $output): void
    {
        $output->writeln(sprintf('journey_key: %s', $report->journeyKey));
        $output->writeln(sprintf('match_process_keys: %s', $report->matchProcessKeys === [] ? '<none>' : implode(', ', $report->matchProcessKeys)));
        $output->writeln(sprintf('candidate_documents: %d', count($report->rows)));

        foreach ($report->warnings as $warning) {
            $output->writeln(sprintf('<comment>warning: %s</comment>', $warning));
        }

        foreach ($report->rows as $row) {
            $output->writeln('');
            $output->writeln(sprintf('document_uuid: %s', $row->documentRef->documentUuid));
            $output->writeln(sprintf('status: %s', $row->status()));
            if ($row->error !== null) {
                $output->writeln(sprintf('  - %s', $row->error));
                continue;
            }

            if (($row->result?->unexpectedProcesses ?? []) !== []) {
                $output->writeln('Unexpected processes:');
                foreach ($row->result->unexpectedProcesses as $unexpectedProcess) {
                    $output->writeln(sprintf('%s %s', $unexpectedProcess->severity, $unexpectedProcess->code));
                    $output->writeln($unexpectedProcess->message);
                    $output->writeln(sprintf(
                        'Process: %s%s',
                        $unexpectedProcess->processKey,
                        $unexpectedProcess->occurredAt === null ? '' : ' at '.$unexpectedProcess->occurredAt->format(DATE_ATOM)
                    ));
                }
            }

            foreach ($this->notableStepResults($row) as $stepResult) {
                $output->writeln(sprintf(
                    '  - step %s [%s]: %s',
                    $stepResult->journeyStepKey,
                    $stepResult->processKey ?? '',
                    implode('; ', $stepResult->messages) ?: $stepResult->status
                ));
            }
            foreach ($this->notableTransitionResults($row) as $transitionResult) {
                $output->writeln(sprintf(
                    '  - transition %s -> %s: %s',
                    $transitionResult->fromStepKey,
                    $transitionResult->toStepKey,
                    implode('; ', $transitionResult->messages) ?: $transitionResult->status
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function reportToArray(JourneyDocumentCheckReport $report): array
    {
        return [
            'journey_key' => $report->journeyKey,
            'match_process_keys' => $report->matchProcessKeys,
            'candidate_documents' => count($report->rows),
            'warnings' => $report->warnings,
            'documents' => array_map(
                fn (JourneyDocumentCheckRow $row): array => $this->rowToArray($row),
                $report->rows
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowToArray(JourneyDocumentCheckRow $row): array
    {
        return [
            'document_uuid' => $row->documentRef->documentUuid,
            'document_external_id' => $row->documentRef->documentExternalId,
            'document_version' => $row->documentRef->documentVersion,
            'status' => $row->status(),
            'error' => $row->error,
            'steps' => array_map(
                static fn (JourneyTemplateStepCheckResult $step): array => [
                    'status' => $step->status,
                    'key' => $step->journeyStepKey,
                    'process_key' => $step->processKey,
                    'required' => $step->required,
                    'messages' => $step->messages,
                ],
                $row->result?->stepResults ?? []
            ),
            'transitions' => array_map(
                static fn (JourneyTemplateTransitionCheckResult $transition): array => [
                    'status' => $transition->status,
                    'from' => $transition->fromStepKey,
                    'to' => $transition->toStepKey,
                    'messages' => $transition->messages,
                ],
                $row->result?->transitionResults ?? []
            ),
            'unexpected_processes' => array_map(
                static fn (UnexpectedProcessResult $unexpectedProcess): array => [
                    'code' => $unexpectedProcess->code,
                    'status' => $unexpectedProcess->status,
                    'severity' => $unexpectedProcess->severity,
                    'processKey' => $unexpectedProcess->processKey,
                    'message' => $unexpectedProcess->message,
                    'timelineIndex' => $unexpectedProcess->timelineIndex,
                    'occurredAt' => $unexpectedProcess->occurredAt?->format(DATE_ATOM),
                    'documentVersion' => $unexpectedProcess->documentVersion,
                ],
                $row->result?->unexpectedProcesses ?? []
            ),
        ];
    }

    /**
     * @return array<int, JourneyTemplateStepCheckResult>
     */
    private function notableStepResults(JourneyDocumentCheckRow $row): array
    {
        return array_values(array_filter(
            $row->result?->stepResults ?? [],
            static fn (JourneyTemplateStepCheckResult $result): bool => in_array($result->status, [
                JourneyTemplateCheckService::STEP_MISSING_REQUIRED_PROCESS,
                JourneyTemplateCheckService::STEP_WARNING,
            ], true)
        ));
    }

    /**
     * @return array<int, JourneyTemplateTransitionCheckResult>
     */
    private function notableTransitionResults(JourneyDocumentCheckRow $row): array
    {
        return array_values(array_filter(
            $row->result?->transitionResults ?? [],
            static fn (JourneyTemplateTransitionCheckResult $result): bool => in_array($result->status, [
                JourneyTemplateCheckService::TRANSITION_WRONG_ORDER,
                JourneyTemplateCheckService::TRANSITION_WARNING,
            ], true)
        ));
    }
}
