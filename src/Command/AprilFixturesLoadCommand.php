<?php

namespace App\Command;

use App\Intelligence\Application\EventReceiver;
use App\Intelligence\Application\ProcessInstanceRepository;
use App\Intelligence\Application\ProcessResetResult;
use App\Intelligence\Application\ProcessResetter;
use JsonException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

#[AsCommand(
    name: 'april:fixtures:load',
    description: 'Loads APRIL demo fixtures from the demo directory.'
)]
final class AprilFixturesLoadCommand extends Command
{
    private const DEFAULT_SCENARIO = 'incident-management';

    public function __construct(
        private readonly EventReceiver $eventReceiver,
        private readonly ProcessInstanceRepository $processInstanceRepository,
        private readonly KernelInterface $kernel,
        private readonly ?string $demoDirectory = null,
        private readonly ?ProcessResetter $processResetter = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'Demo scenario below demo/', self::DEFAULT_SCENARIO)
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Delete demo data for the selected scenario before loading fixtures.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scenario = trim((string) $input->getOption('scenario'));
        if ($scenario === '' || str_contains($scenario, '..')) {
            $output->writeln('<error>Invalid scenario name.</error>');

            return Command::INVALID;
        }

        try {
            $scenarioDirectory = $this->scenarioDirectory($scenario);
            $files = $this->eventFiles($scenarioDirectory);
            $payloads = $this->payloadsFromFiles($files);
            $resetResult = null;
            if ((bool) $input->getOption('reset')) {
                $resetResult = $this->resetDemoData($payloads);
            }

            $result = $this->loadPayloads($payloads);
        } catch (RuntimeException|JsonException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('scenario: %s', $scenario));
        $output->writeln(sprintf('event_files: %d', count($files)));
        $output->writeln(sprintf('reset: %s', $resetResult instanceof FixtureResetSummary ? 'yes' : 'no'));
        if ($resetResult instanceof FixtureResetSummary) {
            $output->writeln(sprintf('reset_targets: %d', $resetResult->targets));
            $output->writeln(sprintf('deleted_events: %d', $resetResult->events));
            $output->writeln(sprintf('deleted_process_instances: %d', $resetResult->processInstances));
            $output->writeln(sprintf('deleted_context_snapshots: %d', $resetResult->contextSnapshots));
        }

        $output->writeln(sprintf('events_imported: %d', $result['imported_events']));
        $output->writeln(sprintf('events_duplicate: %d', $result['duplicate_events']));
        $output->writeln(sprintf('process_instances: %d', $result['process_instances']));
        $output->writeln(sprintf('process_instances_total: %d', $this->processInstanceRepository->count()));
        $output->writeln('browser_hint: open APRIL after login and start with these demo views');
        $output->writeln(sprintf('browser_process_documents: %s', $this->browserUrl('/app/intelligence/process-keys/'.rawurlencode($scenario).'/documents')));
        $output->writeln(sprintf('browser_template_findings: %s', $this->browserUrl('/app/templates/'.rawurlencode($scenario).'/documents?withFindings=1')));

        return Command::SUCCESS;
    }

    private function scenarioDirectory(string $scenario): string
    {
        $demoRoot = $this->demoDirectory ?? $this->kernel->getProjectDir().'/demo';
        $directory = rtrim($demoRoot, '/').'/'.$scenario;
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('Demo scenario "%s" was not found in %s.', $scenario, $demoRoot));
        }

        return $directory;
    }

    /**
     * @return array<int, string>
     */
    private function eventFiles(string $scenarioDirectory): array
    {
        $files = glob(rtrim($scenarioDirectory, '/').'/events-*.json') ?: [];
        natsort($files);
        $files = array_values($files);
        if ($files === []) {
            throw new RuntimeException(sprintf('No event fixture files found in %s.', $scenarioDirectory));
        }

        return $files;
    }

    /**
     * @param array<int, string> $files
     * @return array<int, array<string, mixed>>
     *
     * @throws JsonException
     */
    private function payloadsFromFiles(array $files): array
    {
        $payloads = [];
        foreach ($files as $file) {
            array_push($payloads, ...$this->payloadsFromFile($file));
        }

        return $payloads;
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     * @return array{imported_events: int, duplicate_events: int, process_instances: int}
     *
     * @throws JsonException
     */
    private function loadPayloads(array $payloads): array
    {
        $importedEvents = 0;
        $duplicateEvents = 0;
        $processInstanceIds = [];

        foreach ($payloads as $payload) {
            $result = $this->eventReceiver->receive($payload, json_encode($payload, JSON_THROW_ON_ERROR));
            if ($result->duplicate) {
                ++$duplicateEvents;
            } else {
                ++$importedEvents;
            }

            if ($result->event->processInstanceId !== null) {
                $processInstanceIds[$result->event->processInstanceId] = true;
            }
        }

        return [
            'imported_events' => $importedEvents,
            'duplicate_events' => $duplicateEvents,
            'process_instances' => count($processInstanceIds),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     */
    private function resetDemoData(array $payloads): FixtureResetSummary
    {
        if (!$this->processResetter instanceof ProcessResetter) {
            throw new RuntimeException('Fixture reset is not available because no process resetter is configured.');
        }

        $targets = $this->resetTargets($payloads);
        $summary = new FixtureResetSummary(0, 0, 0, 0);

        foreach ($targets as $target) {
            $result = $this->processResetter->reset($target['processKey'], $target['documentUuid']);
            $summary = $summary->with($result);
        }

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $payloads
     * @return array<int, array{processKey: string, documentUuid: string}>
     */
    private function resetTargets(array $payloads): array
    {
        $targets = [];
        foreach ($payloads as $payload) {
            $processKey = $this->stringValue($payload, ['process_key', 'processKey']);
            $document = is_array($payload['document'] ?? null) ? $payload['document'] : [];
            $documentUuid = $this->stringValue($payload + $document, ['document_uuid', 'documentUuid', 'externalUuid', 'uuid']);
            if ($processKey === null || $documentUuid === null) {
                throw new RuntimeException('Fixture reset requires every event payload to define processKey and documentUuid.');
            }

            $targets[$processKey.'|'.$documentUuid] = [
                'processKey' => $processKey,
                'documentUuid' => $documentUuid,
            ];
        }

        return array_values($targets);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     */
    private function stringValue(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key]) && trim((string) $payload[$key]) !== '') {
                return trim((string) $payload[$key]);
            }
        }

        return null;
    }

    private function browserUrl(string $path): string
    {
        $baseUri = trim((string) ($_SERVER['DEFAULT_URI'] ?? $_ENV['DEFAULT_URI'] ?? getenv('DEFAULT_URI') ?: ''));
        if ($baseUri === '') {
            $baseUri = 'http://localhost:8080';
        }

        return rtrim($baseUri, '/').$path;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws JsonException
     */
    private function payloadsFromFile(string $file): array
    {
        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read event fixture file %s.', $file));
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Event fixture file %s must contain a JSON array.', $file));
        }

        $payloads = [];
        foreach ($decoded as $index => $payload) {
            if (!is_array($payload)) {
                throw new RuntimeException(sprintf('Event fixture file %s contains a non-object payload at index %d.', $file, $index));
            }

            $payloads[] = $payload;
        }

        return $payloads;
    }
}

final readonly class FixtureResetSummary
{
    public function __construct(
        public int $targets,
        public int $events,
        public int $processInstances,
        public int $contextSnapshots
    ) {
    }

    public function with(ProcessResetResult $result): self
    {
        return new self(
            $this->targets + 1,
            $this->events + $result->processEvents,
            $this->processInstances + $result->processInstances,
            $this->contextSnapshots + $result->contextSnapshots
        );
    }
}
