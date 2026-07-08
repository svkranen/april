<?php

namespace App\Command;

use App\Wizard\WizardDefinitionException;
use App\Wizard\WizardDefinitionLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'april:wizard:list',
    description: 'Lists and validates APRIL wizard definitions.'
)]
final class AprilWizardListCommand extends Command
{
    public function __construct(
        private readonly WizardDefinitionLoader $loader
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $wizards = $this->loader->all();
        } catch (WizardDefinitionException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        if ($wizards === []) {
            $output->writeln('No wizard definitions found.');

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['wizard key', 'version', 'steps', 'status']);
        foreach ($wizards as $wizard) {
            $table->addRow([$wizard->key, $wizard->version, (string) $wizard->stepCount(), 'valid']);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
