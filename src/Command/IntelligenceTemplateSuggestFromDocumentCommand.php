<?php

namespace App\Command;

use App\Intelligence\Application\ProcessTemplateSuggestionService;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Domain\ProcessTemplateSuggestionArraySerializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'intelligence:template:suggest-from-document',
    description: 'Suggests a draft process template from the event timeline of a document.'
)]
final class IntelligenceTemplateSuggestFromDocumentCommand extends Command
{
    public function __construct(
        private readonly ProcessTemplateSuggestionService $suggestionService,
        private readonly ProcessTemplateSuggestionArraySerializer $serializer = new ProcessTemplateSuggestionArraySerializer()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('documentUuid', InputArgument::REQUIRED, 'Document UUID to analyze')
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process key to suggest')
            ->addOption('document-version', null, InputOption::VALUE_REQUIRED, 'Document version to analyze')
            ->addOption('include-before', null, InputOption::VALUE_NONE, 'Include before-phase events in the suggested steps')
            ->addOption('order-by', null, InputOption::VALUE_REQUIRED, 'Event order: occurred-at, received-at, or occurred-then-received', EventTimelineOrder::DEFAULT->value)
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Path to write the YAML template suggestion')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite the output file if it already exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $documentUuid = (string) $input->getArgument('documentUuid');
        $processKey = (string) $input->getArgument('processKey');
        $versionOption = $input->getOption('document-version');
        $documentVersion = $versionOption === null ? null : (int) $versionOption;
        $order = EventTimelineOrder::fromOption((string) $input->getOption('order-by'));
        if ($order === null) {
            $output->writeln(sprintf('<error>Invalid --order-by. Use one of: %s.</error>', implode(', ', EventTimelineOrder::values())));

            return Command::INVALID;
        }

        $suggestion = $this->suggestionService->suggest(
            $documentUuid,
            $processKey,
            $documentVersion,
            $input->getOption('include-before') === true,
            $order
        );
        if ($suggestion === null) {
            $output->writeln(sprintf(
                '<comment>No events found for document "%s" and process "%s".</comment>',
                $documentUuid,
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
        $output->writeln(sprintf('<info>Template suggestion written to %s</info>', $outputPath));

        return Command::SUCCESS;
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
