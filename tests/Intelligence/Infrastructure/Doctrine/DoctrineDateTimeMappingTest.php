<?php

namespace App\Tests\Intelligence\Infrastructure\Doctrine;

use App\Intelligence\Infrastructure\Doctrine\Entity\ContextSnapshotEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\IncomingEventEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessInstanceEntity;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessVersionEntity;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\ORM\Mapping as ORM;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DoctrineDateTimeMappingTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string, string}>
     */
    public static function utcDateTimeColumns(): iterable
    {
        yield 'context snapshot capturedAt' => [ContextSnapshotEntity::class, 'capturedAt'];
        yield 'context snapshot occurredAt' => [ContextSnapshotEntity::class, 'occurredAt'];
        yield 'context snapshot loadedAt' => [ContextSnapshotEntity::class, 'loadedAt'];
        yield 'incoming event occurredAt' => [IncomingEventEntity::class, 'occurredAt'];
        yield 'incoming event receivedAt' => [IncomingEventEntity::class, 'receivedAt'];
        yield 'incoming event processedAt' => [IncomingEventEntity::class, 'processedAt'];
        yield 'incoming event createdAt' => [IncomingEventEntity::class, 'createdAt'];
        yield 'incoming event updatedAt' => [IncomingEventEntity::class, 'updatedAt'];
        yield 'process event occurredAt' => [ProcessEventEntity::class, 'occurredAt'];
        yield 'process event receivedAt' => [ProcessEventEntity::class, 'receivedAt'];
        yield 'process instance startedAt' => [ProcessInstanceEntity::class, 'startedAt'];
        yield 'process instance lastEventAt' => [ProcessInstanceEntity::class, 'lastEventAt'];
        yield 'process instance endedAt' => [ProcessInstanceEntity::class, 'endedAt'];
        yield 'process instance createdAt' => [ProcessInstanceEntity::class, 'createdAt'];
        yield 'process instance updatedAt' => [ProcessInstanceEntity::class, 'updatedAt'];
        yield 'process version validFrom' => [ProcessVersionEntity::class, 'validFrom'];
        yield 'process version createdAt' => [ProcessVersionEntity::class, 'createdAt'];
    }

    /**
     * @dataProvider utcDateTimeColumns
     *
     * @param class-string $entityClass
     */
    public function testUtcDateTimeColumnsUseOffsetlessDoctrineType(string $entityClass, string $propertyName): void
    {
        $property = (new ReflectionClass($entityClass))->getProperty($propertyName);
        $attributes = $property->getAttributes(ORM\Column::class);

        self::assertNotEmpty($attributes);

        $arguments = $attributes[0]->getArguments();

        self::assertSame('datetime_immutable', $arguments['type'] ?? null);
    }

    public function testOffsetlessDatabaseValueConvertsWithDateTimeImmutableType(): void
    {
        $previousTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        $type = new DateTimeImmutableType();

        try {
            $dateTime = $type->convertToPHPValue('2026-05-31 07:08:00', new PostgreSQLPlatform());
        } finally {
            date_default_timezone_set($previousTimezone);
        }

        self::assertSame('2026-05-31T07:08:00+00:00', $dateTime?->format(DATE_ATOM));
    }

    public function testContextSnapshotFreshnessRepairRecalculatesStoredValues(): void
    {
        $snapshot = new ContextSnapshotEntity();
        $snapshot->setOccurredAt(new DateTimeImmutable('2026-05-31T05:08:00+00:00'));
        $snapshot->setLoadedAt(new DateTimeImmutable('2026-05-31T05:11:44+00:00'));
        $snapshot->setFreshnessSeconds(-6976);
        $snapshot->setIsFreshForDecisionCheck(false);

        self::assertTrue($snapshot->recalculateFreshnessForDecisionCheck(300));
        self::assertSame(224, $snapshot->getFreshnessSeconds());
        self::assertTrue($snapshot->isFreshForDecisionCheck());
    }
}
