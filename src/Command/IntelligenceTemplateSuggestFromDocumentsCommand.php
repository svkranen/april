<?php

namespace App\Command;

use App\Intelligence\Application\ProcessTemplateMultiDocumentSuggestionService;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Domain\ProcessTemplateSuggestionArraySerializer;
use DateTimeImmutable;
use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'intelligence:template:suggest-from-documents',
    description: 'Suggests a draft process template from multiple document event timelines.'
)]
final class IntelligenceTemplateSuggestFromDocumentsCommand extends Command
{
    public function __construct(
        private readonly ProcessTemplateMultiDocumentSuggestionService $suggestionService,
        private readonly ProcessTemplateSuggestionArraySerializer $serializer = new ProcessTemplateSuggestionArraySerializer()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process key to suggest')
            ->addArgument('documentUuids', InputArgument::IS_ARRAY, 'Document UUIDs to analyze')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Path to write the YAML template suggestion')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite the output file if it already exists')
            ->addOption('document-version', null, InputOption::VALUE_REQUIRED, 'Document version to analyze for every document')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of documents to auto-select')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only auto-select documents with events at or after this datetime')
            ->addOption('order-by', null, InputOption::VALUE_REQUIRED, 'Event order: occurred-at, received-at, or occurred-then-received', EventTimelineOrder::DEFAULT->value)
            ->addOption('include-before', null, InputOption::VALUE_NONE, 'Include before-phase events in the suggested steps and transitions');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processKey = (string) $input->getArgument('processKey');
        $documentUuids = array_values(array_filter(
            array_map('strval', (array) $input->getArgument('documentUuids')),
            static fn (string $documentUuid): bool => $documentUuid !== ''
        ));
        $versionOption = $input->getOption('document-version');
        $documentVersion = $versionOption === null ? null : (int) $versionOption;
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

        if ($documentUuids === []) {
            $documentUuids = $this->suggestionService->documentUuidsForProcess($processKey, $since, $limit);
            if ($documentUuids === []) {
                $message = sprintf('No documents with events found for process "%s".', $processKey);
                if ($since instanceof DateTimeImmutable) {
                    $message .= sprintf(' Since filter: %s.', $since->format(DATE_ATOM));
                }
                $output->writeln(sprintf('<comment>%s</comment>', $message));

                return Command::FAILURE;
            }
        }

        $suggestion = $this->suggestionService->suggest(
            $documentUuids,
            $processKey,
            $documentVersion,
            $input->getOption('include-before') === true,
            $order
        );
        if ($suggestion === null) {
            $output->writeln(sprintf(
                '<comment>No events found for process "%s" in the given documents.</comment>',
                $processKey
            ));

            return Command::SUCCESS;
        }

        $template = $this->serializer->toArray($suggestion);
        $yaml = Yaml::dump($template, 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
        $outputPath = $input->getOption('output');
        if ($outputPath === null) {
            $output->write($yaml);

            return Command::SUCCESS;
        }

        $outputPath = (string) $outputPath;
        if (file_exists($outputPath) && $input->getOption('force') !== true) {
            $output->writeln(sprintf(
                '<error>Output file already exists: %s. Use --force to overwrite.</error>',
                $outputPath
            ));

            return Command::FAILURE;
        }

        $this->writeOutput($outputPath, $yaml);
        $output->writeln(sprintf(
            '<info>Template suggestion written to %s (%d document(s) used)</info>',
            $outputPath,
            $template['documents_used'] ?? count($documentUuids)
        ));

        return Command::SUCCESS;
    }

    private function sinceOption(mixed $value, OutputInterface $output): DateTimeImmutable|false|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new DateTimeImmutable((string) $value);
        } catch (Exception $exception) {
            $output->writeln(sprintf('<error>Invalid --since datetime: %s</error>', (string) $value));

            return false;
        }
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
