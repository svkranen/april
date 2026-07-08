<?php

namespace App\Command;

use App\Wizard\WizardDefinitionException;
use App\Wizard\WizardDefinitionLoader;
use App\Wizard\WizardDefinitionRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'april:wizard:show',
    description: 'Renders an APRIL wizard definition without executing it.'
)]
final class AprilWizardShowCommand extends Command
{
    public function __construct(
        private readonly WizardDefinitionLoader $loader,
        private readonly WizardDefinitionRenderer $renderer
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('key', InputArgument::REQUIRED, 'Wizard key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $wizard = $this->loader->load((string) $input->getArgument('key'));
        } catch (WizardDefinitionException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $output->write($this->renderer->render($wizard));

        return Command::SUCCESS;
    }
}
