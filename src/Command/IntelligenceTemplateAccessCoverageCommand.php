<?php

namespace App\Command;

use App\Intelligence\Application\AccessCoverageReportBuilder;
use App\Intelligence\Application\ProcessTemplateProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:template:access-coverage',
    description: 'Shows static access-control coverage declared in a YAML process template.'
)]
final class IntelligenceTemplateAccessCoverageCommand extends Command
{
    public function __construct(
        private readonly ProcessTemplateProvider $templateProvider,
        private readonly AccessCoverageReportBuilder $reportBuilder = new AccessCoverageReportBuilder()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process template key')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use one of: text, json.</error>');

            return Command::INVALID;
        }

        $processKey = (string) $input->getArgument('processKey');
        $template = $this->templateProvider->findByProcessKey($processKey);
        if ($template === null) {
            $output->writeln(sprintf('<error>Template "%s" not found.</error>', $processKey));

            return Command::FAILURE;
        }

        $report = $this->reportBuilder->build($template);
        if ($format === 'json') {
            $output->writeln(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Access coverage for %s (source_system: %s)', $report->processKey, $report->sourceSystem));
        $output->writeln(sprintf(
            'Summary: probes=%d, checks=%d, automatic=%d, unsupported=%d, not_covered=%d, manual_tests=%d',
            $report->summary['accessProbes'],
            $report->summary['visibilityChecks'],
            $report->summary['automatic'],
            $report->summary['unsupported'],
            $report->summary['notCovered'],
            $report->summary['manualAccessTests']
        ));

        if ($report->checks !== []) {
            $table = new Table($output);
            $table->setHeaders(['step', 'phase', 'check', 'profiles', 'probes', 'coverage', 'reason']);
            foreach ($report->checks as $check) {
                $table->addRow([
                    $check['stepKey'],
                    $check['phase'],
                    $check['checkKey'],
                    implode(', ', $check['profileKeys']),
                    implode(', ', $check['probeKeys']),
                    $check['coverage'],
                    $check['reason'] ?? '',
                ]);
            }
            $table->render();
        }

        if ($report->manualTests !== []) {
            $table = new Table($output);
            $table->setHeaders(['manual test', 'title', 'frequency']);
            foreach ($report->manualTests as $test) {
                $table->addRow([
                    $test['key'],
                    $test['title'] ?? '',
                    $test['frequency'] ?? '',
                ]);
            }
            $table->render();
        }

        return Command::SUCCESS;
    }
}
