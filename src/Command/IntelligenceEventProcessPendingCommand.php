<?php

namespace App\Command;

use App\Intelligence\Application\IncomingEventProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:event:process-pending',
    description: 'Processes pending asynchronous Intelligence incoming events.'
)]
final class IntelligenceEventProcessPendingCommand extends Command
{
    public function __construct(private readonly IncomingEventProcessor $processor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum incoming events to process', '50')
            ->addOption('max-retries', null, InputOption::VALUE_REQUIRED, 'Retries before an incoming event becomes dead', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->processor->processPending(
            max(1, (int) $input->getOption('limit')),
            max(1, (int) $input->getOption('max-retries'))
        );

        $output->writeln(sprintf('processed: %d', $result->processed));
        $output->writeln(sprintf('failed: %d', $result->failed));
        $output->writeln(sprintf('dead: %d', $result->dead));

        return Command::SUCCESS;
    }
}
