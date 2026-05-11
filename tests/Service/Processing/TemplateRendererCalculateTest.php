<?php

namespace App\Tests\Service\Processing;

use App\Service\Processing\TemplateRenderer;
use PHPUnit\Framework\TestCase;

class TemplateRendererCalculateTest extends TestCase
{
    public function testCalculateAbsWrapsNextLiteral(): void
    {
        $renderer = new TemplateRenderer();
        $method = (new \ReflectionClass($renderer))->getMethod('applyFunction');
        $method->setAccessible(true);

        $function = '[CALCULATE]';
        $content = '[IF][LB][NOT][ISEMPTY][LB][465.43][RB][RB][LP][RET][ABS][465.43][ENDC]'
            . '[RP][ELSE][RET][0][ENDC]';

        $result = $method->invoke($renderer, $function, $content, [], []);

        $this->assertSame('465,43', $result);
    }

    public function testApplyFieldLimitSanitizesPipeAndLineBreaks(): void
    {
        $renderer = new TemplateRenderer();
        $method = (new \ReflectionClass($renderer))->getMethod('applyFieldLimit');
        $method->setAccessible(true);

        $result = $method->invoke(
            $renderer,
            '[:Beschreibung:]',
            "2100423850 |\nzweite Zeile",
            []
        );

        $this->assertSame('2100423850 / zweite Zeile', $result);
    }
}
