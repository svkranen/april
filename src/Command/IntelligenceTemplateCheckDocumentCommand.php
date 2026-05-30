<?php

namespace App\Command;

use App\Intelligence\Application\ProcessTemplateCheckService;
use App\Intelligence\Application\EventTimelineOrder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'intelligence:template:check-document',
    description: 'Checks a document event timeline against a YAML process template.'
)]
final class IntelligenceTemplateCheckDocumentCommand extends Command
{
    public function __construct(
        private readonly ProcessTemplateCheckService $checkService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('documentUuid', InputArgument::REQUIRED, 'Document UUID to check')
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process key to check')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Path to the YAML process template')
            ->addOption('document-version', null, InputOption::VALUE_REQUIRED, 'Document version to check')
            ->addOption('order-by', null, InputOption::VALUE_REQUIRED, 'Event order: occurred-at, received-at, or occurred-then-received', EventTimelineOrder::DEFAULT->value);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $templatePath = $input->getOption('template');
        if ($templatePath === null || !is_string($templatePath) || $templatePath === '') {
            $output->writeln('<error>Missing required --template option.</error>');

            return Command::INVALID;
        }

        if (!is_file($templatePath)) {
            $output->writeln(sprintf('<error>Template file not found: %s</error>', $templatePath));

            return Command::FAILURE;
        }

        $template = Yaml::parseFile($templatePath);
        if (!is_array($template)) {
            $output->writeln(sprintf('<error>Template file is not a YAML mapping: %s</error>', $templatePath));

            return Command::FAILURE;
        }

        $versionOption = $input->getOption('document-version');
        $documentVersion = $versionOption === null ? null : (int) $versionOption;
        $order = EventTimelineOrder::fromOption((string) $input->getOption('order-by'));
        if ($order === null) {
            $output->writeln(sprintf('<error>Invalid --order-by. Use one of: %s.</error>', implode(', ', EventTimelineOrder::values())));

            return Command::INVALID;
        }

        $result = $this->checkService->check(
            (string) $input->getArgument('documentUuid'),
            (string) $input->getArgument('processKey'),
            $template,
            $documentVersion,
            $order
        );

        $output->writeln(sprintf('<info>Status:</info> %s', $result->status()));
        $output->writeln(sprintf('<info>Soll-Schrittfolge:</info> %s', $this->formatSteps($result->expectedSteps)));
        $output->writeln(sprintf('<info>Ist-Schrittfolge:</info> %s', $this->formatSteps($result->actualSteps)));
        $output->writeln('<info>Abweichungen:</info>');

        if ($result->deviations === []) {
            $output->writeln('  - none');

            return Command::SUCCESS;
        }

        foreach ($result->deviations as $deviation) {
            $output->writeln(sprintf('  - %s', $deviation));
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<int, string> $steps
     */
    private function formatSteps(array $steps): string
    {
        return $steps === [] ? '(empty)' : implode(' -> ', $steps);
    }
}
