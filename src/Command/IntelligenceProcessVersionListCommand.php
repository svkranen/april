<?php

namespace App\Command;

use App\Intelligence\Application\ProcessVersionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:process-version:list',
    description: 'Lists Intelligence process versions for a process.'
)]
final class IntelligenceProcessVersionListCommand extends Command
{
    public function __construct(
        private readonly ProcessVersionRepository $processVersionRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('processKey', InputArgument::REQUIRED, 'Process key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $processKey = (string) $input->getArgument('processKey');
        $versions = $this->processVersionRepository->findByProcessKey($processKey);
        if ($versions === []) {
            $output->writeln(sprintf('<comment>No process versions found for "%s".</comment>', $processKey));

            return Command::SUCCESS;
        }

        foreach ($versions as $version) {
            $output->writeln(sprintf(
                '%s %s %s',
                $version->processKey,
                $version->version,
                $version->validFrom->format(DATE_ATOM)
            ));
        }

        return Command::SUCCESS;
    }
}
