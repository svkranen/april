<?php

namespace App\Tests\Intelligence\Architecture;

use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessEventEntity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ProcessEventRawLogTest extends TestCase
{
    public function testProcessEventEntityHasNoKpiOrProcessVersionFields(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(ProcessEventEntity::class))->getProperties()
        );

        foreach ($properties as $property) {
            self::assertStringNotContainsString('kpi', strtolower($property));
            self::assertStringNotContainsString('eligibility', strtolower($property));
            self::assertStringNotContainsString('exclusion', strtolower($property));
            self::assertNotSame('processVersion', $property);
            self::assertNotSame('processVersionId', $property);
        }
    }
}
