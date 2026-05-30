<?php

namespace App\Command;

use App\Intelligence\Application\ProcessResetResult;
use App\Intelligence\Application\ProcessResetter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'intelligence:process:reset',
    description: 'Deletes stored Intelligence data for a process.'
)]
final class IntelligenceProcessResetCommand extends Command
{
    public function __construct(
        private readonly ProcessResetter $resetter,
        private readonly ParameterBagInterface $parameterBag
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process key to reset')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Confirm deletion without an interactive prompt')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted without deleting anything')
            ->addOption('document-uuid', null, InputOption::VALUE_REQUIRED, 'Restrict reset to one document UUID')
            ->addOption('keep-templates', null, InputOption::VALUE_NONE, 'Keep stored templates when template persistence is added later');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processKey = (string) $input->getArgument('processKey');
        $documentUuid = $input->getOption('document-uuid');
        $documentUuid = $documentUuid === null ? null : (string) $documentUuid;
        $dryRun = $input->getOption('dry-run') === true;
        $environment = (string) $this->parameterBag->get('kernel.environment');

        if ($environment === 'prod' && !$dryRun) {
            $output->writeln('<error>Reset is blocked in prod. Use --dry-run for inspection.</error>');

            return Command::FAILURE;
        }

        if (!$dryRun && $input->getOption('yes') !== true && !$this->confirm($input, $output, $processKey, $documentUuid)) {
            $output->writeln('<comment>Reset cancelled. No data was deleted.</comment>');

            return Command::SUCCESS;
        }

        $result = $this->resetter->reset($processKey, $documentUuid, $dryRun);
        $this->writeResult($output, $processKey, $documentUuid, $result, $input->getOption('keep-templates') === true);

        return Command::SUCCESS;
    }

    private function confirm(InputInterface $input, OutputInterface $output, string $processKey, ?string $documentUuid): bool
    {
        if (!$input->isInteractive()) {
            return false;
        }

        $scope = $documentUuid === null ? 'all documents' : sprintf('document "%s"', $documentUuid);
        $question = new ConfirmationQuestion(
            sprintf('Delete Intelligence data for process "%s" (%s)? [y/N] ', $processKey, $scope),
            false
        );

        return (bool) $this->getHelper('question')->ask($input, $output, $question);
    }

    private function writeResult(OutputInterface $output, string $processKey, ?string $documentUuid, ProcessResetResult $result, bool $keepTemplates): void
    {
        $prefix = $result->dryRun ? 'Would delete' : 'Deleted';
        $output->writeln(sprintf(
            '<info>%s Intelligence data for process "%s"%s:</info>',
            $prefix,
            $processKey,
            $documentUuid === null ? '' : sprintf(' and document "%s"', $documentUuid)
        ));
        $output->writeln(sprintf('ProcessEvents: %d', $result->processEvents));
        $output->writeln(sprintf('ProcessInstances: %d', $result->processInstances));
        $output->writeln(sprintf('ContextSnapshots: %d', $result->contextSnapshots));
        $output->writeln(sprintf('Deviations: %d', $result->deviations));
        $output->writeln(sprintf('AnalysisResults: %d', $result->analysisResults));

        if ($keepTemplates) {
            $output->writeln('Templates: kept');
        }
    }
}
