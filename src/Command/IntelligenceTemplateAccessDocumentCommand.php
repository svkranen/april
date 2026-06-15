<?php

namespace App\Command;

use App\Intelligence\Application\AccessDocumentationMarkdownRenderer;
use App\Intelligence\Application\AccessDocumentationHtmlRenderer;
use App\Intelligence\Application\ProcessTemplateProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:template:access-document',
    description: 'Renders human-readable Markdown or HTML documentation for template access controls.'
)]
final class IntelligenceTemplateAccessDocumentCommand extends Command
{
    public function __construct(
        private readonly ProcessTemplateProvider $templateProvider,
        private readonly AccessDocumentationMarkdownRenderer $markdownRenderer = new AccessDocumentationMarkdownRenderer(),
        private readonly AccessDocumentationHtmlRenderer $htmlRenderer = new AccessDocumentationHtmlRenderer()
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
        $processKey = (string) $input->getArgument('processKey');
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['markdown', 'html'], true)) {
            $output->writeln('<error>Invalid --format. Use one of: markdown, html.</error>');

            return Command::INVALID;
        }

        $template = $this->templateProvider->findByProcessKey($processKey);
        if ($template === null) {
            $output->writeln(sprintf('<error>Template "%s" not found.</error>', $processKey));

            return Command::FAILURE;
        }

        $document = $format === 'html'
            ? $this->htmlRenderer->render($template)
            : $this->markdownRenderer->render($template);
        $outputPath = $input->getOption('output');
        if (is_string($outputPath) && trim($outputPath) !== '') {
            $outputPath = trim($outputPath);
            $directory = dirname($outputPath);
            if ($directory !== '' && $directory !== '.' && !is_dir($directory)) {
                mkdir($directory, 0775, true);
            }

            file_put_contents($outputPath, $document);
            $output->writeln(sprintf('Wrote %s', $outputPath));

            return Command::SUCCESS;
        }

        $output->write($document);

        return Command::SUCCESS;
    }
}
