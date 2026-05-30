<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\ContextCoverageReportBuilder;
use App\Intelligence\Domain\ContextSnapshot;
use App\Intelligence\Domain\DocumentRef;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ContextCoverageReportBuilderTest extends TestCase
{
    public function testBuildsCoverageRowsFromContextSnapshots(): void
    {
        $report = (new ContextCoverageReportBuilder())->build('invoice', [
            $this->snapshot(['amount' => 12000, 'documentType' => 'invoice', 'approved' => true]),
            $this->snapshot(['amount' => 2500.5, 'documentType' => 'invoice']),
            $this->snapshot(['documentType' => 'credit_note', 'amount' => null]),
        ]);

        self::assertSame('invoice', $report->processKey);
        self::assertSame(3, $report->snapshotCount);

        $amount = $this->field($report->fields, 'amount');
        self::assertSame(0.6667, $amount->coverage);
        self::assertSame(2, $amount->presentCount);
        self::assertSame(1, $amount->missingCount);
        self::assertSame(['float', 'int'], $amount->observedTypes);
        self::assertSame([12000, 2500.5], $amount->exampleValues);

        $documentType = $this->field($report->fields, 'documentType');
        self::assertSame(1.0, $documentType->coverage);
        self::assertSame(['string'], $documentType->observedTypes);
        self::assertSame(['invoice', 'credit_note'], $documentType->exampleValues);

        $approved = $this->field($report->fields, 'approved');
        self::assertSame(0.3333, $approved->coverage);
        self::assertSame(['bool'], $approved->observedTypes);
        self::assertSame([true], $approved->exampleValues);
    }

    public function testEmptyStringsCountAsMissing(): void
    {
        $report = (new ContextCoverageReportBuilder())->build('invoice', [
            $this->snapshot(['costCenter' => 'KST-1']),
            $this->snapshot(['costCenter' => '']),
        ]);

        $costCenter = $this->field($report->fields, 'costCenter');
        self::assertSame(0.5, $costCenter->coverage);
        self::assertSame(1, $costCenter->presentCount);
        self::assertSame(1, $costCenter->missingCount);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function snapshot(array $attributes): ContextSnapshot
    {
        return new ContextSnapshot(
            new DocumentRef('amagno', 'doc-1', 'uuid-1', 1),
            new DateTimeImmutable('2026-05-29T10:00:00+00:00'),
            $attributes,
            [],
            'invoice',
            'evt-1',
            1
        );
    }

    /**
     * @param array<int, mixed> $fields
     */
    private function field(array $fields, string $fieldKey): mixed
    {
        foreach ($fields as $field) {
            if ($field->fieldKey === $fieldKey) {
                return $field;
            }
        }

        self::fail(sprintf('Field "%s" not found.', $fieldKey));
    }
}
