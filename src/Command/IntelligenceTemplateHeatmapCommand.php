<?php

namespace App\Command;

use App\Intelligence\Application\DocumentTimelineEventRow;
use App\Intelligence\Application\DocumentTimelineProvider;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Application\KpiRelevantTimelineFilter;
use App\Intelligence\Application\ProcessDocumentUuidProvider;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Template\TemplateHeatmapReportBuilder;
use DateTimeImmutable;
use Exception;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'intelligence:template:heatmap',
    description: 'Builds flow and duration heatmaps for document timelines against a process template.'
)]
final class IntelligenceTemplateHeatmapCommand extends Command
{
    public function __construct(
        private readonly TemplateHeatmapReportBuilder $reportBuilder,
        private readonly ProcessDocumentUuidProvider $documentUuidProvider,
        private readonly DocumentTimelineProvider $timelineProvider,
        private readonly KpiRelevantTimelineFilter $timelineFilter,
        private readonly string $processTemplateDirectory,
        private readonly string $heatmapOutputDirectory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process key / runtime template key')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Path to the YAML process template')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: yaml or json', 'yaml')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Path to write the heatmap report')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite the output file if it already exists')
            ->addOption('document-version', null, InputOption::VALUE_REQUIRED, 'Document version to analyze for every document')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of documents to auto-select')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only auto-select documents with events at or after this datetime')
            ->addOption('order-by', null, InputOption::VALUE_REQUIRED, 'Event order: occurred-at, received-at, or occurred-then-received', EventTimelineOrder::DEFAULT->value)
            ->addOption('include-before', null, InputOption::VALUE_NONE, 'Include before-phase events in the heatmap timelines')
            ->addOption('include-excluded', null, InputOption::VALUE_NONE, 'Include timelines excluded from standard KPI/heatmap eligibility and report exclusion reasons')
            ->addOption('keep-direct-repeats', null, InputOption::VALUE_NONE, 'Keep direct repeated steps such as 03 -> 03');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processKey = (string) $input->getArgument('processKey');
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['yaml', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use yaml or json.</error>');

            return Command::INVALID;
        }

        $order = EventTimelineOrder::fromOption((string) $input->getOption('order-by'));
        if ($order === null) {
            $output->writeln(sprintf('<error>Invalid --order-by. Use one of: %s.</error>', implode(', ', EventTimelineOrder::values())));

            return Command::INVALID;
        }

        $limitOption = $input->getOption('limit');
        $limit = $limitOption === null ? null : (int) $limitOption;
        if ($limit !== null && $limit < 1) {
            $output->writeln('<error>Option --limit must be greater than 0.</error>');

            return Command::FAILURE;
        }

        $since = $this->sinceOption($input->getOption('since'), $output);
        if ($since === false) {
            return Command::FAILURE;
        }

        $templatePath = $this->templatePath($processKey, $input->getOption('template'));
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

        $documentUuids = $this->documentUuidProvider->documentUuidsForProcess($processKey, $since, $limit);
        if ($documentUuids === []) {
            $output->writeln(sprintf('<comment>No documents with events found for process "%s".</comment>', $processKey));

            return Command::FAILURE;
        }

        $versionOption = $input->getOption('document-version');
        $documentVersion = $versionOption === null ? null : (int) $versionOption;
        $documentTimelines = $this->documentTimelines(
            $documentUuids,
            $processKey,
            $documentVersion,
            $order,
            $input->getOption('include-before') === true
        );
        if ($documentTimelines === []) {
            $output->writeln(sprintf('<comment>No timeline events found for process "%s".</comment>', $processKey));

            return Command::SUCCESS;
        }

        $filterResult = $this->timelineFilter->filterDocumentTimelines(
            $template,
            $processKey,
            $documentTimelines,
            $input->getOption('include-excluded') === true
        );

        $report = $this->reportBuilder->build(
            $template,
            $filterResult->included,
            new DateTimeImmutable(),
            $input->getOption('keep-direct-repeats') !== true
        );
        $report['kpi_eligibility'] = $filterResult->summary;
        if ($input->getOption('include-excluded') === true) {
            $report['kpi_eligibility']['excluded_timelines'] = $filterResult->excluded;
        }

        try {
            $contents = $format === 'json'
                ? json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
                : Yaml::dump($report, 5, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        } catch (JsonException $exception) {
            $output->writeln(sprintf('<error>Could not encode report: %s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $outputPath = $this->outputPath($processKey, $format, $input->getOption('output'));
        if (file_exists($outputPath) && $input->getOption('force') !== true) {
            $output->writeln(sprintf('<error>Output file already exists: %s. Use --force to overwrite.</error>', $outputPath));

            return Command::FAILURE;
        }

        $this->writeOutput($outputPath, $contents);
        $output->writeln(sprintf('<info>Heatmap report written to %s (%d document(s) used)</info>', $outputPath, $report['documents_used']));

        return Command::SUCCESS;
    }

    private function templatePath(string $processKey, mixed $templateOption): string
    {
        if ($templateOption !== null && $templateOption !== '') {
            return (string) $templateOption;
        }

        return rtrim($this->processTemplateDirectory, '/') . '/' . $processKey . '.yaml';
    }

    private function outputPath(string $processKey, string $format, mixed $outputOption): string
    {
        if ($outputOption !== null && $outputOption !== '') {
            return (string) $outputOption;
        }

        $extension = $format === 'json' ? 'json' : 'yaml';

        return rtrim($this->heatmapOutputDirectory, '/') . '/' . $processKey . '-heatmap.' . $extension;
    }

    private function sinceOption(mixed $value, OutputInterface $output): DateTimeImmutable|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Exception) {
            $output->writeln(sprintf('<error>Invalid --since datetime: %s</error>', (string) $value));

            return false;
        }
    }

    /**
     * @param array<int, string> $documentUuids
     * @return array<int, array{document_uuid: string, timeline: array<int, array{step: string, occurred_at: string}>}>
     */
    private function documentTimelines(
        array $documentUuids,
        string $processKey,
        ?int $documentVersion,
        EventTimelineOrder $order,
        bool $includeBefore
    ): array {
        $documentTimelines = [];
        foreach ($documentUuids as $documentUuid) {
            $events = array_values(array_filter(
                $this->timelineProvider->build($documentUuid, $order)->events,
                static fn (DocumentTimelineEventRow $event): bool => $event->processKey === $processKey
                    && ($documentVersion === null || $event->documentVersion === $documentVersion)
                    && ($includeBefore || $event->eventPhase === 'after')
            ));

            if ($events === []) {
                continue;
            }

            usort($events, static fn (DocumentTimelineEventRow $left, DocumentTimelineEventRow $right): int => $order->compareTimelineRows($left, $right));

            $documentTimelines[] = [
                'document_uuid' => $documentUuid,
                'timeline' => array_map(
                    static fn (DocumentTimelineEventRow $event): array => [
                        'step' => $event->stepKey,
                        'occurred_at' => $event->occurredAt->format(DATE_ATOM),
                    ],
                    $events
                ),
            ];
        }

        return $documentTimelines;
    }

    private function writeOutput(string $path, string $contents): void
    {
        $directory = dirname($path);
        if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, $contents);
    }
}
