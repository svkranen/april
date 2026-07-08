<?php

namespace App\Command;

use App\Intelligence\Application\EventReceiver;
use App\Intelligence\Application\ProcessInstanceRepository;
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
        private readonly ?string $demoDirectory = null
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('scenario', null, InputOption::VALUE_REQUIRED, 'Demo scenario below demo/', self::DEFAULT_SCENARIO);
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
            $result = $this->loadFiles($files);
        } catch (RuntimeException|JsonException $exception) {
            $output->writeln(sprintf('<error>%s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('scenario: %s', $scenario));
        $output->writeln(sprintf('event_files: %d', count($files)));
        $output->writeln(sprintf('events_imported: %d', $result['imported_events']));
        $output->writeln(sprintf('events_duplicate: %d', $result['duplicate_events']));
        $output->writeln(sprintf('process_instances: %d', $result['process_instances']));
        $output->writeln(sprintf('process_instances_total: %d', $this->processInstanceRepository->count()));

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
     * @return array{imported_events: int, duplicate_events: int, process_instances: int}
     *
     * @throws JsonException
     */
    private function loadFiles(array $files): array
    {
        $importedEvents = 0;
        $duplicateEvents = 0;
        $processInstanceIds = [];

        foreach ($files as $file) {
            foreach ($this->payloadsFromFile($file) as $payload) {
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
        }

        return [
            'imported_events' => $importedEvents,
            'duplicate_events' => $duplicateEvents,
            'process_instances' => count($processInstanceIds),
        ];
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
