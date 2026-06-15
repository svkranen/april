<?php

namespace App\Command;

use App\Intelligence\Application\AccessProbeProviderRegistry;
use App\Intelligence\Application\ProcessTemplateProvider;
use App\Intelligence\Application\VisibilityCheckService;
use App\Intelligence\Application\VisibilityCheckResultSaveContext;
use App\Intelligence\Application\VisibilityCheckResultStore;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Infrastructure\Access\InMemoryAccessProbeProvider;
use App\Intelligence\Port\AccessProbeProvider;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:access:check-document',
    description: 'Runs template visibility checks against registered access probe providers.'
)]
final class IntelligenceAccessCheckDocumentCommand extends Command
{
    /**
     * @param iterable<AccessProbeProvider> $accessProbeProviders
     */
    public function __construct(
        private readonly ProcessTemplateProvider $templateProvider,
        private readonly iterable $accessProbeProviders = [],
        private readonly ?VisibilityCheckResultStore $resultStore = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('processKey', InputArgument::REQUIRED, 'Process template key')
            ->addArgument('documentUuid', InputArgument::REQUIRED, 'Document UUID')
            ->addOption('step', null, InputOption::VALUE_REQUIRED, 'Step key to evaluate')
            ->addOption('phase', null, InputOption::VALUE_REQUIRED, 'Event phase: before or after', 'after')
            ->addOption('check', null, InputOption::VALUE_REQUIRED, 'Optional visibility check key; defaults to all checks in step/phase')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'JSON object with context fields', '{}')
            ->addOption('fake-visible-probes', null, InputOption::VALUE_REQUIRED, 'Comma-separated probe keys treated as visible by the fake provider', '')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text')
            ->addOption('persist', null, InputOption::VALUE_NONE, 'Persist visibility check results')
            ->addOption('event-key', null, InputOption::VALUE_REQUIRED, 'Optional external event key for persisted results')
            ->addOption('document-version', null, InputOption::VALUE_REQUIRED, 'Optional document version for persisted results');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = (string) $input->getOption('format');
        if (!in_array($format, ['text', 'json'], true)) {
            $output->writeln('<error>Invalid --format. Use one of: text, json.</error>');

            return Command::INVALID;
        }

        $processKey = (string) $input->getArgument('processKey');
        $documentUuid = (string) $input->getArgument('documentUuid');
        $stepKey = trim((string) $input->getOption('step'));
        $phase = trim((string) $input->getOption('phase'));
        if ($stepKey === '') {
            $output->writeln('<error>Missing required --step option.</error>');

            return Command::INVALID;
        }
        if (!in_array($phase, ['before', 'after'], true)) {
            $output->writeln('<error>Invalid --phase. Use one of: before, after.</error>');

            return Command::INVALID;
        }

        try {
            $context = $this->context((string) $input->getOption('context'));
        } catch (JsonException) {
            $output->writeln('<error>Invalid --context JSON.</error>');

            return Command::INVALID;
        }

        $template = $this->templateProvider->findByProcessKey($processKey);
        if ($template === null) {
            $output->writeln(sprintf('<error>Template "%s" not found.</error>', $processKey));

            return Command::FAILURE;
        }

        $service = new VisibilityCheckService(new AccessProbeProviderRegistry(
            $this->providers((string) $input->getOption('fake-visible-probes'))
        ));

        $checkKeys = $this->checkKeys($template, $stepKey, $phase, $input->getOption('check'));
        $results = [];
        foreach ($checkKeys as $checkKey) {
            foreach ($service->evaluate($template, $documentUuid, $stepKey, $phase, $checkKey, $context) as $result) {
                $results[] = $result;
            }
        }

        $persistedCount = 0;
        if ((bool) $input->getOption('persist')) {
            if ($this->resultStore === null) {
                $output->writeln('<error>Visibility check result store is not configured.</error>');

                return Command::FAILURE;
            }

            $persistedCount = $this->resultStore->saveMany($results, new VisibilityCheckResultSaveContext(
                externalEventKey: $this->nullableOption($input->getOption('event-key')),
                documentVersion: $this->nullableIntOption($input->getOption('document-version')),
                sourceSystem: $template->sourceSystem
            ));
        }

        if ($format === 'json') {
            $output->writeln(json_encode([
                'documentUuid' => $documentUuid,
                'processKey' => $processKey,
                'stepKey' => $stepKey,
                'eventPhase' => $phase,
                'persisted' => (bool) $input->getOption('persist'),
                'persistedCount' => $persistedCount,
                'results' => array_map(static fn ($result): array => $result->toArray(), $results),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['checkKey', 'profileKey', 'probeKey', 'expected', 'actual', 'status', 'reason']);
        foreach ($results as $result) {
            $table->addRow([
                $result->checkKey,
                $result->profileKey,
                $result->probeKey,
                $result->expected,
                $result->actual,
                $result->status,
                $result->reason ?? '',
            ]);
        }
        $table->render();
        if ((bool) $input->getOption('persist')) {
            $output->writeln(sprintf('Persisted visibility check results: %d', $persistedCount));
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function context(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, string>
     */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn (string $part): string => trim($part),
            explode(',', $value)
        ), static fn (string $part): bool => $part !== ''));
    }

    private function nullableOption(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableIntOption(mixed $value): ?int
    {
        if (!is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return iterable<AccessProbeProvider>
     */
    private function providers(string $fakeVisibleProbes): iterable
    {
        if (trim($fakeVisibleProbes) !== '') {
            return [
                new InMemoryAccessProbeProvider(
                    $this->csv($fakeVisibleProbes),
                    [
                        'fake:fake_document_visibility',
                        'amagno:amagno_magnet_documents',
                    ]
                ),
            ];
        }

        return $this->accessProbeProviders;
    }

    private function checkKeys(ProcessTemplate $template, string $stepKey, string $phase, mixed $checkOption): array
    {
        $selected = is_scalar($checkOption) ? trim((string) $checkOption) : '';
        if ($selected !== '') {
            return [$selected];
        }

        foreach ($template->steps as $step) {
            if ($step->key !== $stepKey) {
                continue;
            }

            $checks = $phase === 'before' ? $step->beforeVisibilityChecks : $step->afterVisibilityChecks;

            return array_map(static fn ($check): string => $check->key, $checks);
        }

        return [];
    }
}
