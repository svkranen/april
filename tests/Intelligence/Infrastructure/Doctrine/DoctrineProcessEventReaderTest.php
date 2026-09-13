<?php

namespace App\Tests\Intelligence\Infrastructure\Doctrine;

use App\Intelligence\Application\ProcessInstanceManager;
use App\Intelligence\Application\ProcessKpiMeasurements;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessInstanceEntity;
use App\Intelligence\Infrastructure\Doctrine\Repository\DoctrineEventStore;
use App\Intelligence\Infrastructure\Doctrine\Repository\DoctrineProcessInstanceRepository;
use App\Intelligence\Infrastructure\Process\InMemoryProcessVersionRepository;
use App\Tests\Intelligence\Domain\Fixtures\KpiScenario as Scenario;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

final class DoctrineProcessEventReaderTest extends TestCase
{
    public function testStoredHistorySuppliesBothPhasesAndLateEventsWithoutChangingEvents(): void
    {
        $paths = [dirname(__DIR__, 4).'/src/Intelligence/Infrastructure/Doctrine/Entity'];
        $configuration = PHP_VERSION_ID >= 80400
            ? ORMSetup::createAttributeMetadataConfig($paths, true)
            : ORMSetup::createAttributeMetadataConfiguration($paths, true);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $manager = new EntityManager($connection, $configuration);
        (new SchemaTool($manager))->createSchema([
            $manager->getClassMetadata(ProcessEventEntity::class),
            $manager->getClassMetadata(ProcessInstanceEntity::class),
        ]);
        try {
            $store = new DoctrineEventStore($manager);
            $events = Scenario::linear();
            foreach (array_slice($events, 1) as $event) {
                $store->append($event);
            }
            $other = $events[4]->toArray();
            $other['externalEventKey'] = 'other-process-end';
            $other['processKey'] = 'other';
            $store->append(ProcessEventRecord::fromArray($other));
            $service = new ProcessKpiMeasurements($store, new InMemoryProcessVersionRepository(Scenario::versions()));
            self::assertFalse($service->forTemplate(Scenario::template(), Scenario::definition())[0]->e2eDuration->isMeasurable());
            $start = Scenario::event('s', 'start', 'before', '2026-01-31T23:00:00Z', '2026-03-01T12:00:00Z');
            $stored = $store->append($start)->event;
            $instance = (new ProcessInstanceManager(new DoctrineProcessInstanceRepository($manager)))->findOrCreateForEvent($stored, '1');
            $store->attachProcessInstance($stored, $instance->id);
            self::assertTrue($store->append($start)->duplicate);
            $manager->clear();
            $before = $connection->fetchAllAssociative('SELECT * FROM intelligence_process_event ORDER BY id');
            $run = $service->forTemplate(Scenario::template(), Scenario::definition())[0];
            self::assertSame(7200.0, $run->e2eDuration->seconds);
            self::assertSame(3600.0, $run->stepVisits[1]->duration->seconds);
            self::assertCount(5, $run->eventKeys);
            self::assertSame([$instance->id], $run->processInstanceIds);
            self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM intelligence_process_event ORDER BY id'));
        } finally {
            $manager->close();
            $connection->close();
        }
    }
}
