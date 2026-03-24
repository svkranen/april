<?php

namespace App\Tests\SignatureCheck;

use App\SignatureCheck\SignatureCompletenessChecker;
use PHPUnit\Framework\TestCase;

class SignatureCompletenessCheckerTest extends TestCase
{
    public function testItMatchesRequiredAndConfirmedNamesCaseInsensitive(): void
    {
        $checker = new SignatureCompletenessChecker();

        $result = $checker->check(
            ['Anna', 'Bernd', 'Claudia'],
            ['anna', 'Claudia', 'Bernd']
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame([], $result->missingNames);
        $this->assertSame([], $result->unexpectedNames);
    }

    public function testItReportsMissingAndUnexpectedNames(): void
    {
        $checker = new SignatureCompletenessChecker();

        $result = $checker->check(
            ['Anna', 'Bernd', 'Claudia'],
            ['Anna', 'Dirk']
        );

        $this->assertFalse($result->isComplete());
        $this->assertSame(['Bernd', 'Claudia'], $result->missingNames);
        $this->assertSame(['Dirk'], $result->unexpectedNames);
    }

    public function testItIgnoresDuplicateRequiredNames(): void
    {
        $checker = new SignatureCompletenessChecker();

        $result = $checker->check(
            ['Anna', 'Anna', 'Bernd'],
            ['Anna', 'Bernd']
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame(['Anna', 'Bernd'], $result->requiredNames);
        $this->assertSame([], $result->missingNames);
    }

    public function testItIgnoresDuplicateConfirmedNames(): void
    {
        $checker = new SignatureCompletenessChecker();

        $result = $checker->check(
            ['Anna', 'Bernd'],
            ['Anna', 'Anna', 'Bernd', 'Bernd']
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame(['Anna', 'Bernd'], $result->confirmedNames);
        $this->assertSame([], $result->unexpectedNames);
    }
}
