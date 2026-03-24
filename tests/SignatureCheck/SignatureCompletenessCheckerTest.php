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

    public function testItRespectsDuplicateRequiredNames(): void
    {
        $checker = new SignatureCompletenessChecker();

        $result = $checker->check(
            ['Anna', 'Anna', 'Bernd'],
            ['Anna', 'Bernd']
        );

        $this->assertFalse($result->isComplete());
        $this->assertSame(['Anna'], $result->missingNames);
    }
}
