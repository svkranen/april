<?php

namespace App\Command;

use App\Intelligence\Bpmn\BpmnMermaidRenderer;
use App\Intelligence\Bpmn\BpmnSvgRenderer;
use App\Intelligence\Bpmn\BpmnSvgRenderOptions;
use App\Intelligence\Bpmn\ProcessTemplateBpmnViewBuilder;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(
    name: 'intelligence:template:bpmn-view',
    description: 'Builds a neutral BPMN-like view model from a process template and an optional heatmap report.'
)]
final class IntelligenceTemplateBpmnViewCommand extends Command
{
    public function __construct(
        private readonly ProcessTemplateBpmnViewBuilder $builder,
        private readonly BpmnMermaidRenderer $mermaidRenderer = new BpmnMermaidRenderer(),
        private readonly BpmnSvgRenderer $svgRenderer = new BpmnSvgRenderer()
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process key / runtime template key')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Path to the YAML process template')
            ->addOption('heatmap', null, InputOption::VALUE_REQUIRED, 'Optional path to a JSON or YAML heatmap report')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: json, mermaid, or svg', 'json')
            ->addOption('view', null, InputOption::VALUE_REQUIRED, 'Rendered view: summary, expected, observed, deviations, or combined')
            ->addOption('min-unexpected-count', null, InputOption::VALUE_REQUIRED, 'Minimum count for unexpected observed edges in rendered combined/deviations views', '2')
            ->addOption('width', null, InputOption::VALUE_REQUIRED, 'SVG width in pixels', '1200')
            ->addOption('compact', null, InputOption::VALUE_NEGATABLE, 'Render SVG in compact mode', true)
            ->addOption('expand-parallel-groups', null, InputOption::VALUE_NONE, 'Render parallel groups as expanded Mermaid subgraphs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = strtolower((string) $input->getOption('format'));
        if (!in_array($format, ['json', 'mermaid', 'svg'], true)) {
            $output->writeln('<error>Invalid --format. Use json, mermaid, or svg.</error>');

            return Command::INVALID;
        }
        $viewOption = $input->getOption('view');
        $viewMode = $viewOption === null || $viewOption === ''
            ? ($format === 'svg' ? 'summary' : 'combined')
            : strtolower((string) $viewOption);
        if (!in_array($viewMode, ['summary', 'expected', 'observed', 'deviations', 'combined'], true)) {
            $output->writeln('<error>Invalid --view. Use summary, expected, observed, deviations, or combined.</error>');

            return Command::INVALID;
        }
        $minUnexpectedCount = (int) $input->getOption('min-unexpected-count');
        if ($minUnexpectedCount < 1) {
            $output->writeln('<error>Option --min-unexpected-count must be greater than 0.</error>');

            return Command::INVALID;
        }
        $width = (int) $input->getOption('width');
        if ($width < 400) {
            $output->writeln('<error>Option --width must be at least 400.</error>');

            return Command::INVALID;
        }

        $templatePath = $input->getOption('template');
        if (!is_string($templatePath) || $templatePath === '') {
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

        $processKey = (string) $input->getArgument('processKey');
        $template = ProcessTemplateArrayFactory::fromArray($templateData);
        if ($template->key !== $processKey) {
            $output->writeln(sprintf('<comment>Template key "%s" differs from requested process "%s".</comment>', $template->key, $processKey));
        }

        try {
            $heatmap = $this->heatmapReport($input->getOption('heatmap'));
            $view = $this->builder->build($template, $heatmap);
            if ($format === 'mermaid') {
                $output->write($this->mermaidRenderer->render(
                    $view,
                    $viewMode,
                    $minUnexpectedCount,
                    $input->getOption('expand-parallel-groups') === true
                ));

                return Command::SUCCESS;
            }

            if ($format === 'svg') {
                $output->write($this->svgRenderer->render(
                    $view,
                    new BpmnSvgRenderOptions(
                        $viewMode,
                        $minUnexpectedCount,
                        $width,
                        $input->getOption('compact') === true
                    )
                ));

                return Command::SUCCESS;
            }

            $output->writeln(json_encode($view->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (JsonException|RuntimeException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function heatmapReport(mixed $path): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (!is_string($path) || !is_file($path)) {
            throw new RuntimeException(sprintf('Heatmap file not found: %s', (string) $path));
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $data = $extension === 'json'
            ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
            : Yaml::parseFile($path);

        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Heatmap file is not a mapping: %s', $path));
        }

        return $data;
    }
}
