<?php

namespace App\Command;

use App\Intelligence\Application\CrossProcessRoutingChecker;
use App\Intelligence\Application\CrossProcessRoutingRuleCheckResult;
use App\Intelligence\Application\EventTimelineOrder;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'intelligence:template:check-journey',
    description: 'Checks read-only cross-process routing rules for a document journey.'
)]
final class IntelligenceTemplateCheckJourneyCommand extends Command
{
    public function __construct(
        private readonly CrossProcessRoutingChecker $checker
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('documentUuid', InputArgument::REQUIRED, 'Document UUID to check')
            ->addArgument('sourceProcessKey', InputArgument::REQUIRED, 'Source process key that contains cross-process routing rules')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Path to the YAML source process template')
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

        $templateData = Yaml::parseFile($templatePath);
        if (!is_array($templateData)) {
            $output->writeln(sprintf('<error>Template file is not a YAML mapping: %s</error>', $templatePath));

            return Command::FAILURE;
        }

        $order = EventTimelineOrder::fromOption((string) $input->getOption('order-by'));
        if ($order === null) {
            $output->writeln(sprintf('<error>Invalid --order-by. Use one of: %s.</error>', implode(', ', EventTimelineOrder::values())));

            return Command::INVALID;
        }

        $versionOption = $input->getOption('document-version');
        $documentVersion = $versionOption === null ? null : (int) $versionOption;
        $result = $this->checker->check(
            (string) $input->getArgument('documentUuid'),
            (string) $input->getArgument('sourceProcessKey'),
            ProcessTemplateArrayFactory::fromArray($templateData),
            $documentVersion,
            $order
        );

        $output->writeln(sprintf('<info>Status:</info> %s', $result->status));
        $output->writeln(sprintf('<info>Source process:</info> %s', $result->sourceProcessKey));

        if ($result->ruleResults === []) {
            $output->writeln('<comment>No cross-process routing rules found in template.</comment>');

            return Command::SUCCESS;
        }

        foreach ($result->ruleResults as $ruleResult) {
            $this->writeRuleResult($output, $ruleResult);
        }

        return Command::SUCCESS;
    }

    private function writeRuleResult(OutputInterface $output, CrossProcessRoutingRuleCheckResult $result): void
    {
        $output->writeln('');
        $output->writeln(sprintf('<info>Rule:</info> %s', $result->ruleKey));
        $output->writeln(sprintf('<info>Rule status:</info> %s', $result->status));
        $output->writeln(sprintf('<info>After step:</info> %s', $result->afterStep));
        $output->writeln(sprintf('<info>Expected process:</info> %s', $result->expectedProcess));
        $output->writeln(sprintf('<info>Document version:</info> %s', $result->documentVersion === null ? '' : (string) $result->documentVersion));
        $output->writeln(sprintf('<info>Routing occurred at:</info> %s', $result->routingOccurredAt?->format(DATE_ATOM) ?? ''));
        $output->writeln(sprintf('<info>Target started at:</info> %s', $result->targetStartedAt?->format(DATE_ATOM) ?? ''));

        foreach ($result->messages as $message) {
            $output->writeln(sprintf('  - %s', $message));
        }
    }
}
