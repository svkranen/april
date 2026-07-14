<?php

namespace App\Command;

use App\Intelligence\Application\HtmlProcessTemplateDocumentationRenderer;
use App\Intelligence\Application\MarkdownProcessTemplateDocumentationRenderer;
use App\Intelligence\Application\ProcessTemplateDocumentationFactory;
use App\Intelligence\Application\ProcessTemplateProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:template:document',
    description: 'Renders human-readable Markdown or HTML documentation for a process template.'
)]
final class IntelligenceTemplateDocumentCommand extends Command
{
    public function __construct(
        private readonly ProcessTemplateProvider $templateProvider,
        private readonly ProcessTemplateDocumentationFactory $documentationFactory,
        private readonly MarkdownProcessTemplateDocumentationRenderer $markdownRenderer,
        private readonly HtmlProcessTemplateDocumentationRenderer $htmlRenderer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process template key')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: markdown or html', 'markdown')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write documentation to this file instead of stdout');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processKey = trim((string) $input->getArgument('processKey'));
        $format = strtolower(trim((string) $input->getOption('format')));
        if (!in_array($format, ['markdown', 'html'], true)) {
            $output->writeln('<error>Invalid --format. Use one of: markdown, html.</error>');

            return Command::INVALID;
        }

        $template = $this->templateProvider->findByProcessKey($processKey);
        if ($template === null) {
            $output->writeln(sprintf('<error>Template "%s" not found.</error>', $processKey));

            return Command::FAILURE;
        }

        $documentation = $this->documentationFactory->create($template);
        $rendered = $format === 'html'
            ? $this->htmlRenderer->render($documentation)
            : $this->markdownRenderer->render($documentation);

        $outputPath = $input->getOption('output');
        if (is_string($outputPath) && trim($outputPath) !== '') {
            $path = trim($outputPath);
            $directory = dirname($path);
            if ($directory !== '.' && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                $output->writeln(sprintf('<error>Could not create output directory "%s".</error>', $directory));

                return Command::FAILURE;
            }
            if (@file_put_contents($path, $rendered) === false) {
                $output->writeln(sprintf('<error>Could not write output file "%s".</error>', $path));

                return Command::FAILURE;
            }
            $output->writeln(sprintf('Wrote %s', $path));

            return Command::SUCCESS;
        }

        $output->write($rendered);

        return Command::SUCCESS;
    }
}
