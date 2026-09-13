<?php

namespace App\Tests\Intelligence\Domain\Fixtures;

use App\Intelligence\Domain\KpiEventMarker;
use App\Intelligence\Domain\ProcessEventRecord;
use App\Intelligence\Domain\ProcessKpiDefinition;
use App\Intelligence\Domain\ProcessRunMeasurement;
use App\Intelligence\Domain\ProcessRunReconstructor;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessVersion;
use DateTimeImmutable;

final class KpiScenario
{
    public static function template(): ProcessTemplate
    {
        return new ProcessTemplate('review', '1', initialStepKey: 'start', steps: array_map(
            static fn (string $key): ProcessTemplateStep => new ProcessTemplateStep($key),
            ['start', 'A', 'B', 'end', 'optional']
        ), sourceSystem: 'acme');
    }

    public static function definition(): ProcessKpiDefinition
    {
        return ProcessKpiDefinition::forTemplate(self::template(), '1', KpiEventMarker::at('start', 'before'), [
            [KpiEventMarker::at('end', 'after')],
        ]);
    }

    public static function event(string $key, string $step, string $phase, string $time, ?string $received = null, string $item = 'item-1', int $version = 1): ProcessEventRecord
    {
        return new ProcessEventRecord(null, $key, 'acme', 'review', $step.'_'.$phase, $step, $item, null,
            $version, null, new DateTimeImmutable($time), new DateTimeImmutable($received ?? $time), '{}', '{}', eventPhase: $phase);
    }

    /** @return list<ProcessVersion> */
    public static function versions(): array
    {
        return [new ProcessVersion(null, 'review', '1', new DateTimeImmutable('2026-01-01T00:00:00Z'))];
    }

    /** @param list<ProcessEventRecord> $events @return list<ProcessRunMeasurement> */
    public static function runs(array $events): array
    {
        return (new ProcessRunReconstructor())->reconstruct(self::template(), self::definition(), $events, self::versions());
    }

    /** @return list<ProcessEventRecord> */
    public static function linear(): array
    {
        return [
            self::event('s', 'start', 'before', '2026-01-31T23:00:00Z'),
            self::event('sa', 'start', 'after', '2026-01-31T23:01:00Z'),
            self::event('ab', 'A', 'before', '2026-01-31T23:30:00Z'),
            self::event('aa', 'A', 'after', '2026-02-01T00:30:00Z'),
            self::event('e', 'end', 'after', '2026-02-01T01:00:00Z'),
        ];
    }
}
