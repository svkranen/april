<?php

namespace App\Command;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[AsCommand(
    name: 'intelligence:template:list',
    description: 'Lists available YAML process templates.'
)]
final class IntelligenceTemplateListCommand extends Command
{
    public function __construct(
        private readonly string $templateDirectory
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use one of: text, json.</error>');

            return Command::INVALID;
        }

        $result = $this->loadTemplates();

        if ($format === 'json') {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        if ($result['templates'] === []) {
            $output->writeln('No templates found.');
        } else {
            $table = new Table($output);
            $table->setHeaders(['key', 'version', 'path']);
            foreach ($result['templates'] as $template) {
                $table->addRow([$template['key'], $template['version'], $template['path']]);
            }
            $table->render();
        }

        if ($result['warnings'] !== []) {
            $output->writeln('<comment>Warnings:</comment>');
            foreach ($result['warnings'] as $warning) {
                $output->writeln(sprintf('  - %s: %s', $warning['path'], $warning['message']));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{templates: array<int, array{key: string, version: string, path: string}>, warnings: array<int, array{path: string, message: string}>}
     */
    private function loadTemplates(): array
    {
        $templates = [];
        $warnings = [];
        $paths = glob(rtrim($this->templateDirectory, '/').'/*.yaml') ?: [];
        sort($paths);

        foreach ($paths as $path) {
            try {
                $template = $this->loadTemplate($path);
                $templates[] = [
                    'key' => $template->key,
                    'version' => $template->version,
                    'path' => $path,
                ];
            } catch (Throwable $exception) {
                $warnings[] = [
                    'path' => $path,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'templates' => $templates,
            'warnings' => $warnings,
        ];
    }

    private function loadTemplate(string $path): ProcessTemplate
    {
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new ParseException(sprintf('Invalid YAML: %s', $exception->getMessage()), 0, $exception);
        }

        if (!is_array($data)) {
            throw new \RuntimeException('Template file is not a YAML mapping.');
        }

        $template = ProcessTemplateArrayFactory::fromArray($data);
        if ($template->key === '') {
            throw new \RuntimeException('Template key is missing.');
        }

        return $template;
    }
}
