<?php

namespace App\Intelligence\Infrastructure\Demo;

use App\Intelligence\Domain\ProcessEventRecord;
use DateTimeImmutable;
use DateTimeZone;

/** Deterministic, connector-neutral events for the invoice KPI demo. */
final class InvoiceKpiDemoFixture
{
    public const PROCESS_KEY = 'invoice-receipt';
    public const SOURCE_SYSTEM = 'april-demo';
    public const TEMPLATE_VERSION = '1';

    /** @return list<ProcessEventRecord> */
    public function events(): array
    {
        $events = [];
        $departments = ['Verwaltung', 'IT', 'Produktion', 'Lager'];
        for ($index = 1; $index <= 20; ++$index) {
            $amountIsHigh = $index % 2 === 0;
            $start = $index === 1
                ? new DateTimeImmutable('2026-01-31T22:30:00+00:00')
                : new DateTimeImmutable(sprintf('2026-%02d-%02dT%02d:00:00+00:00', $index <= 10 ? 1 : 2, $index <= 10 ? $index + 10 : $index - 10, 8 + ($index % 4)));
            $events = array_merge($events, $this->invoiceEvents($index, $start, $departments[($index - 1) % 4], $amountIsHigh));
        }

        return $events;
    }

    /** @return list<ProcessEventRecord> */
    private function invoiceEvents(int $number, DateTimeImmutable $start, string $department, bool $highAmount): array
    {
        $id = sprintf('invoice-kpi-%02d', $number);
        $uuid = sprintf('00000000-0000-4000-8000-%012d', $number);
        $steps = [
            ['invoice_received', 'before', 0], ['invoice_received', 'after', 5],
            ['review_assignment', 'before', 35], ['review_assignment', 'after', 65],
            ['department_approval', 'before', 120], ['department_approval', 'after', 180],
        ];
        if ($highAmount) {
            $steps[] = ['management_approval', 'before', 220];
            $steps[] = ['management_approval', 'after', 300];
        }
        if ($number === 3) {
            array_splice($steps, 4, 0, [
                ['review_assignment', 'before', 75],
                ['review_assignment', 'after', 95],
            ]);
        }
        $steps = array_merge($steps, [
            ['preposting', 'before', 340], ['preposting', 'after', 390],
            ['fibu_export', 'before', 430], ['fibu_export', 'after', 470],
            ['booking', 'after', 520], ['payment', 'before', 650],
        ]);
        if ($number === 20) {
            array_pop($steps); // Keep this run open at the payment step.
        }
        if ($number === 19) {
            array_shift($steps); // Completed but without a complete E2E start marker.
        }

        $events = [];
        foreach ($steps as $position => [$step, $phase, $offset]) {
            $occurredAt = $start->modify(sprintf('+%d minutes', $offset));
            $receivedAt = $number === 7 && $position === 1 ? $occurredAt->modify('+2 hours') : $occurredAt->modify('+3 seconds');
            $eventKey = sprintf('invoice-kpi-%02d-%02d', $number, $position + 1);
            $payload = [
                'demo' => 'invoice-kpi',
                'department' => $department,
                'amount_band' => $highAmount ? '10000-plus' : 'under-10000',
            ];
            $events[] = new ProcessEventRecord(
                null, $eventKey, self::SOURCE_SYSTEM, self::PROCESS_KEY, $step.'_'.$phase, $step,
                $id, $uuid, 1, 'demo-fixture', $occurredAt->setTimezone(new DateTimeZone('UTC')),
                $receivedAt->setTimezone(new DateTimeZone('UTC')), json_encode($payload, JSON_THROW_ON_ERROR),
                json_encode($payload, JSON_THROW_ON_ERROR), null, $phase
            );
        }

        return $events;
    }
}
