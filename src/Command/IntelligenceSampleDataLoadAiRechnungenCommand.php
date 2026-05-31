<?php

namespace App\Command;

use App\Intelligence\Application\ContextSnapshotStore;
use App\Intelligence\Application\ProcessInstanceManager;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Doctrine\Entity\ContextSnapshotEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\IncomingEventEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessInstanceEntity;
use App\Intelligence\Port\EventStore;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use JsonException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[AsCommand(
    name: 'intelligence:sample-data:load-ai-rechnungen',
    description: 'Loads dev/test sample process data for the ai-rechnungen template.'
)]
final class IntelligenceSampleDataLoadAiRechnungenCommand extends Command
{
    private const PROCESS_KEY = 'ai-rechnungen';
    private const SOURCE_SYSTEM = 'sample';
    private const DOCUMENT_VERSION = 1;
    private const FIXTURE_BASE = 'base';
    private const FIXTURE_DWELL_GRADIENT = 'dwell-gradient';
    private const BASE_DOCUMENT_IDS = [
        '900001',
        '900002',
        '900003',
        '900004',
        '900005',
        '900006',
        '900007',
        '900008',
    ];
    private const DWELL_GRADIENT_DOCUMENT_IDS = [
        '901001',
        '901002',
        '901003',
        '901004',
        '901005',
        '901006',
        '901007',
        '901008',
        '901009',
        '901010',
        '901011',
        '901012',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventStore $eventStore,
        private readonly ProcessInstanceManager $processInstanceManager,
        private readonly ContextSnapshotStore $contextSnapshotStore,
        private readonly ParameterBagInterface $parameterBag
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fixture', null, InputOption::VALUE_REQUIRED, 'Fixture to load: base or dwell-gradient', self::FIXTURE_BASE)
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Remove existing ai-rechnungen sample documents for the selected fixture before loading')
            ->addOption('purge-all-samples', null, InputOption::VALUE_NONE, 'With --purge, remove all ai-rechnungen sample fixtures')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow loading outside dev/test after confirmation');
    }

    /**
     * @throws JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $environment = (string) $this->parameterBag->get('kernel.environment');
        if (!in_array($environment, ['dev', 'test'], true) && !$this->confirmNonDev($input, $output, $environment)) {
            $output->writeln('<comment>Sample import cancelled. No data was written.</comment>');

            return Command::SUCCESS;
        }

        $fixture = (string) $input->getOption('fixture');
        if (!in_array($fixture, [self::FIXTURE_BASE, self::FIXTURE_DWELL_GRADIENT], true)) {
            $output->writeln(sprintf('<error>Unknown fixture "%s". Supported fixtures: base, dwell-gradient.</error>', $fixture));

            return Command::INVALID;
        }

        $purged = ['events' => 0, 'instances' => 0, 'snapshots' => 0, 'incoming_events' => 0];
        if ($input->getOption('purge') === true) {
            $purged = $this->purgeSamples($this->purgeDocumentIds($fixture, $input->getOption('purge-all-samples') === true));
        }

        $documents = $this->sampleDocuments($fixture);
        $events = 0;
        $snapshots = 0;
        foreach ($documents as $document) {
            $events += $this->loadDocument($document, $snapshots);
        }

        $output->writeln(sprintf('process_key: %s', self::PROCESS_KEY));
        $output->writeln(sprintf('fixture: %s', $fixture));
        $output->writeln(sprintf('documents: %d', count($documents)));
        $output->writeln(sprintf('events: %d', $events));
        $output->writeln(sprintf('context_snapshots: %d', $snapshots));
        if ($input->getOption('purge') === true) {
            $output->writeln(sprintf(
                'purged: events=%d instances=%d snapshots=%d incoming_events=%d',
                $purged['events'],
                $purged['instances'],
                $purged['snapshots'],
                $purged['incoming_events']
            ));
        }
        $output->writeln(sprintf('expected_deviations: %s', implode(', ', $this->expectedDeviationDocuments($fixture))));
        if ($fixture === self::FIXTURE_DWELL_GRADIENT) {
            $output->writeln('hint: Danach Heatmap/Metrics neu erzeugen.');
        }

        return Command::SUCCESS;
    }

    private function confirmNonDev(InputInterface $input, OutputInterface $output, string $environment): bool
    {
        if ($input->getOption('force') !== true || !$input->isInteractive()) {
            $output->writeln(sprintf('<error>Sample import is intended for dev/test only. Current environment: %s.</error>', $environment));

            return false;
        }

        $question = new ConfirmationQuestion(
            sprintf('Load ai-rechnungen sample data in "%s" environment? [y/N] ', $environment),
            false
        );

        return (bool) $this->getHelper('question')->ask($input, $output, $question);
    }

    /**
     * @return array{events: int, instances: int, snapshots: int, incoming_events: int}
     */
    private function purgeSamples(array $documentIds): array
    {
        $criteria = [
            'processKey' => self::PROCESS_KEY,
            'documentExternalId' => $documentIds,
        ];
        $snapshots = $this->entityManager->getRepository(ContextSnapshotEntity::class)->findBy($criteria);
        $events = $this->entityManager->getRepository(ProcessEventEntity::class)->findBy($criteria);
        $instances = $this->entityManager->getRepository(ProcessInstanceEntity::class)->findBy($criteria);
        $incomingEvents = $this->entityManager->getRepository(IncomingEventEntity::class)->findBy([
            'processKey' => self::PROCESS_KEY,
            'documentId' => $documentIds,
        ]);

        foreach ([$snapshots, $events, $instances, $incomingEvents] as $entities) {
            foreach ($entities as $entity) {
                $this->entityManager->remove($entity);
            }
        }
        $this->entityManager->flush();

        return [
            'events' => count($events),
            'instances' => count($instances),
            'snapshots' => count($snapshots),
            'incoming_events' => count($incomingEvents),
        ];
    }

    /**
     * @param array{document_id: string, document_uuid: string, context: array<string, mixed>, events: array<int, array{step: string, occurred_at: string}>} $document
     *
     * @throws JsonException
     */
    private function loadDocument(array $document, int &$snapshots): int
    {
        $eventCount = 0;
        foreach ($document['events'] as $index => $eventData) {
            $occurredAt = $this->utc($eventData['occurred_at']);
            $receivedAt = $occurredAt->modify('+1 second');
            $externalEventKey = sprintf('sample-ai-rechnungen-%s-%02d', $document['document_id'], $index + 1);
            $payload = [
                'source_system' => self::SOURCE_SYSTEM,
                'process_key' => self::PROCESS_KEY,
                'document_id' => $document['document_id'],
                'document_uuid' => $document['document_uuid'],
                'document_version' => self::DOCUMENT_VERSION,
                'step_key' => $eventData['step'],
                'event_key' => $eventData['step'],
                'event_phase' => 'after',
                'occurred_at' => $occurredAt->format(DATE_ATOM),
                'context' => $document['context'],
                'sample' => true,
            ];

            $event = new ProcessEventRecord(
                null,
                $externalEventKey,
                self::SOURCE_SYSTEM,
                self::PROCESS_KEY,
                $eventData['step'],
                $eventData['step'],
                $document['document_id'],
                $document['document_uuid'],
                self::DOCUMENT_VERSION,
                'sample-loader',
                $occurredAt,
                $receivedAt,
                json_encode($payload, JSON_THROW_ON_ERROR),
                json_encode($payload, JSON_THROW_ON_ERROR),
                null,
                'after'
            );

            $result = $this->eventStore->append($event);
            if ($result->duplicate) {
                continue;
            }

            $instance = $this->processInstanceManager->findOrCreateForEvent($result->event);
            $eventWithInstance = $this->eventStore->attachProcessInstance($result->event, (int) $instance->id);
            $loadedAt = $occurredAt->modify('+60 seconds');
            $this->contextSnapshotStore->save(new ContextSnapshot(
                new DocumentRef(self::SOURCE_SYSTEM, $document['document_id'], $document['document_uuid'], self::DOCUMENT_VERSION),
                $loadedAt,
                $document['context'],
                [],
                self::PROCESS_KEY,
                $externalEventKey,
                $eventWithInstance->processInstanceId,
                $occurredAt,
                $loadedAt,
                null,
                60,
                true
            ));

            ++$eventCount;
            ++$snapshots;
        }

        return $eventCount;
    }

    private function utc(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @return list<string>
     */
    private function purgeDocumentIds(string $fixture, bool $purgeAllSamples): array
    {
        if ($purgeAllSamples) {
            return array_values(array_merge(self::BASE_DOCUMENT_IDS, self::DWELL_GRADIENT_DOCUMENT_IDS));
        }

        return $fixture === self::FIXTURE_DWELL_GRADIENT ? self::DWELL_GRADIENT_DOCUMENT_IDS : self::BASE_DOCUMENT_IDS;
    }

    /**
     * @return list<string>
     */
    private function expectedDeviationDocuments(string $fixture): array
    {
        return $fixture === self::FIXTURE_DWELL_GRADIENT ? ['901011', '901012'] : ['900006'];
    }

    /**
     * @return array<int, array{document_id: string, document_uuid: string, context: array<string, mixed>, events: array<int, array{step: string, occurred_at: string}>}>
     */
    private function sampleDocuments(string $fixture): array
    {
        return $fixture === self::FIXTURE_DWELL_GRADIENT ? $this->dwellGradientDocuments() : $this->baseDocuments();
    }

    /**
     * @return array<int, array{document_id: string, document_uuid: string, context: array<string, mixed>, events: array<int, array{step: string, occurred_at: string}>}>
     */
    private function baseDocuments(): array
    {
        return [
            [
                'document_id' => '900001',
                'document_uuid' => '00000000-0000-4000-8000-000000900001',
                'context' => ['invoice_direction' => 'RE - Ausgang', 'amount_net' => 400.0],
                'events' => [
                    ['step' => '01 Rechnungen pruefen', 'occurred_at' => '2026-05-31T08:00:00+00:00'],
                    ['step' => '02 Versenden', 'occurred_at' => '2026-05-31T08:05:00+00:00'],
                    ['step' => '05 Ausgangsrechnung buchen', 'occurred_at' => '2026-05-31T08:20:00+00:00'],
                    ['step' => '07 Zahlungseingang erwartet', 'occurred_at' => '2026-05-31T08:30:00+00:00'],
                    ['step' => '09 Rechnungen Abschluss', 'occurred_at' => '2026-05-31T08:35:00+00:00'],
                ],
            ],
            [
                'document_id' => '900002',
                'document_uuid' => '00000000-0000-4000-8000-000000900002',
                'context' => ['invoice_direction' => 'RE - Ausgang', 'amount_net' => 1250.0],
                'events' => [
                    ['step' => '01 Rechnungen pruefen', 'occurred_at' => '2026-05-31T09:00:00+00:00'],
                    ['step' => '02 Versenden', 'occurred_at' => '2026-05-31T09:03:00+00:00'],
                    ['step' => '07 Zahlungseingang erwartet', 'occurred_at' => '2026-05-31T09:12:00+00:00'],
                    ['step' => '05 Ausgangsrechnung buchen', 'occurred_at' => '2026-05-31T09:20:00+00:00'],
                    ['step' => '09 Rechnungen Abschluss', 'occurred_at' => '2026-05-31T09:28:00+00:00'],
                ],
            ],
            [
                'document_id' => '900003',
                'document_uuid' => '00000000-0000-4000-8000-000000900003',
                'context' => ['invoice_direction' => 'RE - Eingang', 'amount_net' => 400.0],
                'events' => [
                    ['step' => '01 Rechnungen pruefen', 'occurred_at' => '2026-05-31T10:00:00+00:00'],
                    ['step' => '03 Freigabe_klein', 'occurred_at' => '2026-05-31T10:04:00+00:00'],
                    ['step' => '05 Ausgangsrechnung buchen', 'occurred_at' => '2026-05-31T10:20:00+00:00'],
                    ['step' => '07 Zahlungseingang erwartet', 'occurred_at' => '2026-05-31T10:45:00+00:00'],
                    ['step' => '09 Rechnungen Abschluss', 'occurred_at' => '2026-05-31T10:50:00+00:00'],
                ],
            ],
            [
                'document_id' => '900004',
                'document_uuid' => '00000000-0000-4000-8000-000000900004',
                'context' => ['invoice_direction' => 'RE - Eingang', 'amount_net' => 1750.0],
                'events' => [
                    ['step' => '01 Rechnungen pruefen', 'occurred_at' => '2026-05-31T11:00:00+00:00'],
                    ['step' => '03 Freigabe_klein', 'occurred_at' => '2026-05-31T11:05:00+00:00'],
                    ['step' => '04 Freigabe_gross', 'occurred_at' => '2026-05-31T11:40:00+00:00'],
                    ['step' => '05 Ausgangsrechnung buchen', 'occurred_at' => '2026-05-31T12:10:00+00:00'],
                    ['step' => '07 Zahlungseingang erwartet', 'occurred_at' => '2026-05-31T12:15:00+00:00'],
                    ['step' => '09 Rechnungen Abschluss', 'occurred_at' => '2026-05-31T12:20:00+00:00'],
                ],
            ],
            [
                'document_id' => '900005',
                'document_uuid' => '00000000-0000-4000-8000-000000900005',
                'context' => ['invoice_direction' => 'RE - Eingang', 'amount_net' => 25.0],
                'events' => [
                    ['step' => '01 Rechnungen pruefen', 'occurred_at' => '2026-05-31T13:00:00+00:00'],
                    ['step' => '05 Ausgangsrechnung buchen', 'occurred_at' => '2026-05-31T13:02:00+00:00'],
                    ['step' => '07 Zahlungseingang erwartet', 'occurred_at' => '2026-05-31T13:12:00+00:00'],
                    ['step' => '09 Rechnungen Abschluss', 'occurred_at' => '2026-05-31T13:15:00+00:00'],
                ],
            ],
            [
                'document_id' => '900006',
                'document_uuid' => '00000000-0000-4000-8000-000000900006',
                'context' => ['invoice_direction' => 'RE - Ausgang', 'amount_net' => 400.0],
                'events' => [
                    ['step' => '01 Rechnungen pruefen', 'occurred_at' => '2026-05-31T14:00:00+00:00'],
                    ['step' => '02 Versenden', 'occurred_at' => '2026-05-31T14:04:00+00:00'],
                    ['step' => '01 Rechnungen pruefen', 'occurred_at' => '2026-05-31T14:15:00+00:00'],
                    ['step' => '07 Zahlungseingang erwartet', 'occurred_at' => '2026-05-31T14:30:00+00:00'],
                ],
            ],
            [
                'document_id' => '900007',
                'document_uuid' => '00000000-0000-4000-8000-000000900007',
                'context' => ['invoice_direction' => 'RE - Eingang', 'amount_net' => 2200.0],
                'events' => [
                    ['step' => '01 Rechnungen pruefen', 'occurred_at' => '2026-05-31T15:00:00+00:00'],
                    ['step' => '03 Freigabe_klein', 'occurred_at' => '2026-05-31T15:05:00+00:00'],
                    ['step' => '04 Freigabe_gross', 'occurred_at' => '2026-05-31T15:45:00+00:00'],
                    ['step' => '07 Zahlungseingang erwartet', 'occurred_at' => '2026-05-31T16:00:00+00:00'],
                    ['step' => '05 Ausgangsrechnung buchen', 'occurred_at' => '2026-05-31T16:30:00+00:00'],
                    ['step' => '09 Rechnungen Abschluss', 'occurred_at' => '2026-05-31T16:40:00+00:00'],
                ],
            ],
            [
                'document_id' => '900008',
                'document_uuid' => '00000000-0000-4000-8000-000000900008',
                'context' => ['invoice_direction' => 'RE - Eingang', 'amount_net' => 850.0],
                'events' => [
                    ['step' => '01 Rechnungen pruefen', 'occurred_at' => '2026-05-31T17:00:00+00:00'],
                    ['step' => '03 Freigabe_klein', 'occurred_at' => '2026-05-31T17:05:00+00:00'],
                    ['step' => '07 Zahlungseingang erwartet', 'occurred_at' => '2026-05-31T17:20:00+00:00'],
                    ['step' => '05 Ausgangsrechnung buchen', 'occurred_at' => '2026-05-31T17:50:00+00:00'],
                    ['step' => '09 Rechnungen Abschluss', 'occurred_at' => '2026-05-31T18:00:00+00:00'],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array{document_id: string, document_uuid: string, context: array<string, mixed>, events: array<int, array{step: string, occurred_at: string}>}>
     */
    private function dwellGradientDocuments(): array
    {
        return [
            $this->document('901001', 'RE - Ausgang', 400.0, '2026-06-01T08:00:00+00:00', ['01 Rechnungen pruefen', '02 Versenden', '05 Ausgangsrechnung buchen', '07 Zahlungseingang erwartet', '09 Rechnungen Abschluss'], [120, 600, 1200, 2700]),
            $this->document('901002', 'RE - Ausgang', 1250.0, '2026-06-01T09:00:00+00:00', ['01 Rechnungen pruefen', '02 Versenden', '07 Zahlungseingang erwartet', '05 Ausgangsrechnung buchen', '09 Rechnungen Abschluss'], [600, 1200, 2700, 5400]),
            $this->document('901003', 'RE - Eingang', 400.0, '2026-06-01T10:00:00+00:00', ['01 Rechnungen pruefen', '03 Freigabe_klein', '05 Ausgangsrechnung buchen', '07 Zahlungseingang erwartet', '09 Rechnungen Abschluss'], [1200, 2700, 5400, 10800]),
            $this->document('901004', 'RE - Eingang', 1750.0, '2026-06-01T11:00:00+00:00', ['01 Rechnungen pruefen', '03 Freigabe_klein', '04 Freigabe_gross', '05 Ausgangsrechnung buchen', '07 Zahlungseingang erwartet', '09 Rechnungen Abschluss'], [2700, 5400, 10800, 21600, 40000]),
            $this->document('901005', 'RE - Eingang', 25.0, '2026-06-01T12:00:00+00:00', ['01 Rechnungen pruefen', '05 Ausgangsrechnung buchen', '07 Zahlungseingang erwartet', '09 Rechnungen Abschluss'], [5400, 10800, 21600]),
            $this->document('901006', 'RE - Ausgang', 400.0, '2026-06-01T13:00:00+00:00', ['01 Rechnungen pruefen', '02 Versenden', '05 Ausgangsrechnung buchen', '07 Zahlungseingang erwartet', '09 Rechnungen Abschluss'], [10800, 21600, 40000, 120]),
            $this->document('901007', 'RE - Eingang', 2200.0, '2026-06-01T14:00:00+00:00', ['01 Rechnungen pruefen', '03 Freigabe_klein', '04 Freigabe_gross', '07 Zahlungseingang erwartet', '05 Ausgangsrechnung buchen', '09 Rechnungen Abschluss'], [21600, 40000, 120, 600, 1200]),
            $this->document('901008', 'RE - Eingang', 850.0, '2026-06-01T15:00:00+00:00', ['01 Rechnungen pruefen', '03 Freigabe_klein', '07 Zahlungseingang erwartet', '05 Ausgangsrechnung buchen', '09 Rechnungen Abschluss'], [40000, 120, 600, 2700]),
            $this->document('901009', 'RE - Ausgang', 700.0, '2026-06-01T16:00:00+00:00', ['01 Rechnungen pruefen', '02 Versenden', '07 Zahlungseingang erwartet', '05 Ausgangsrechnung buchen', '09 Rechnungen Abschluss'], [300, 900, 1800, 3600]),
            $this->document('901010', 'RE - Eingang', 1200.0, '2026-06-01T17:00:00+00:00', ['01 Rechnungen pruefen', '03 Freigabe_klein', '04 Freigabe_gross', '05 Ausgangsrechnung buchen', '07 Zahlungseingang erwartet', '09 Rechnungen Abschluss'], [900, 1800, 3600, 7200, 14400]),
            $this->document('901011', 'RE - Ausgang', 400.0, '2026-06-01T18:00:00+00:00', ['01 Rechnungen pruefen', '02 Versenden', '01 Rechnungen pruefen', '07 Zahlungseingang erwartet'], [600, 1200, 1800]),
            $this->document('901012', 'RE - Eingang', 2500.0, '2026-06-01T19:00:00+00:00', ['01 Rechnungen pruefen', '03 Freigabe_klein', '07 Zahlungseingang erwartet', '09 Rechnungen Abschluss'], [1800, 3600, 7200]),
        ];
    }

    /**
     * @param list<string> $steps
     * @param list<int> $durationsSeconds
     *
     * @return array{document_id: string, document_uuid: string, context: array<string, mixed>, events: array<int, array{step: string, occurred_at: string}>}
     */
    private function document(string $documentId, string $invoiceDirection, float $amountNet, string $startAt, array $steps, array $durationsSeconds): array
    {
        $occurredAt = $this->utc($startAt);
        $events = [];
        foreach ($steps as $index => $step) {
            if ($index > 0) {
                $occurredAt = $occurredAt->modify(sprintf('+%d seconds', $durationsSeconds[$index - 1]));
            }
            $events[] = [
                'step' => $step,
                'occurred_at' => $occurredAt->format(DATE_ATOM),
            ];
        }

        return [
            'document_id' => $documentId,
            'document_uuid' => sprintf('00000000-0000-4000-8000-000000%s', $documentId),
            'context' => ['invoice_direction' => $invoiceDirection, 'amount_net' => $amountNet],
            'events' => $events,
        ];
    }
}
