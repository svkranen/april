<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\ProcessTemplateSignCheck;
use App\Intelligence\Domain\SignCheckEvaluator;
use App\Intelligence\Domain\SignCheckResult;
use PHPUnit\Framework\TestCase;

final class SignCheckEvaluatorTest extends TestCase
{
    /**
     * @dataProvider signCheckCases
     *
     * @param array<string, mixed> $context
     */
    public function testSignCheckStatuses(array $context, string $expectedStatus, int $requiredCount, int $actualCount, int $missingCount, int $unexpectedCount = 0): void
    {
        $result = (new SignCheckEvaluator())->evaluate($this->check(), $context);

        self::assertSame($expectedStatus, $result->status);
        self::assertSame($requiredCount, $result->requiredCount);
        self::assertSame($actualCount, $result->actualCount);
        self::assertSame($missingCount, $result->missingCount);
        self::assertSame($unexpectedCount, $result->unexpectedCount);
    }

    public static function signCheckCases(): iterable
    {
        yield 'satisfied' => [
            ['ToBeSignedBy' => ['A', 'B'], 'SignedBy' => ['A', 'B']],
            SignCheckResult::STATUS_SATISFIED,
            2,
            2,
            0,
        ];
        yield 'partial' => [
            ['ToBeSignedBy' => ['A', 'B', 'C'], 'SignedBy' => ['A', 'C']],
            SignCheckResult::STATUS_PARTIAL,
            3,
            2,
            1,
        ];
        yield 'missing all' => [
            ['ToBeSignedBy' => ['A', 'B'], 'SignedBy' => []],
            SignCheckResult::STATUS_MISSING_ALL,
            2,
            0,
            2,
        ];
        yield 'empty required set' => [
            ['ToBeSignedBy' => [], 'SignedBy' => ['A']],
            SignCheckResult::STATUS_EMPTY_REQUIRED_SET,
            0,
            1,
            0,
            1,
        ];
        yield 'unexpected signer' => [
            ['ToBeSignedBy' => ['A', 'B'], 'SignedBy' => ['A', 'B', 'X']],
            SignCheckResult::STATUS_UNEXPECTED_SIGNER,
            2,
            3,
            0,
            1,
        ];
    }

    public function testMissingContext(): void
    {
        $result = (new SignCheckEvaluator())->evaluate($this->check(), [
            'ToBeSignedBy' => ['A', 'B'],
        ]);

        self::assertSame(SignCheckResult::STATUS_MISSING_CONTEXT, $result->status);
        self::assertSame(['SignedBy'], $result->missingContextFields);
    }

    public function testScalarContextValuesAreNormalizedToSets(): void
    {
        $result = (new SignCheckEvaluator())->evaluate($this->check(), [
            'ToBeSignedBy' => 'A; B; A',
            'SignedBy' => 'B, A',
        ]);

        self::assertSame(SignCheckResult::STATUS_SATISFIED, $result->status);
        self::assertSame(2, $result->requiredCount);
        self::assertSame(2, $result->actualCount);
    }

    private function check(): ProcessTemplateSignCheck
    {
        return new ProcessTemplateSignCheck('bauleiter_freigabe', 'ToBeSignedBy', 'SignedBy');
    }
}
