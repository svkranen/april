<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\DocumentCheckResultView;
use App\Intelligence\Application\ProcessTemplateCheckResult;
use App\Intelligence\Domain\SignCheckResult;
use PHPUnit\Framework\TestCase;

class DocumentCheckResultViewTest extends TestCase
{
    public function testMapsCheckResultFields(): void
    {
        $signCheck = new SignCheckResult('four_eyes', 'Vier-Augen', SignCheckResult::STATUS_PARTIAL, 2, 1, 1, 1, 0, [], ['Bob'], []);
        $result = new ProcessTemplateCheckResult(
            ['01', '02'],
            ['01'],
            ['Missing required step 02'],
            ['Parallelgruppe X unvollstaendig'],
            ['Context fehlt: standort'],
            null,
            [$signCheck]
        );

        $view = DocumentCheckResultView::fromResult($result);

        self::assertTrue($view->available);
        self::assertNull($view->error);
        self::assertSame('DEVIATION', $view->status);
        self::assertFalse($view->isOk);
        self::assertSame(['01', '02'], $view->expectedSteps);
        self::assertSame(['01'], $view->actualSteps);
        self::assertTrue($view->hasActualSteps());
        self::assertSame(['Missing required step 02'], $view->deviations);
        self::assertSame(['Parallelgruppe X unvollstaendig'], $view->parallelGroupMessages);
        self::assertSame(['Context fehlt: standort'], $view->warnings);
        self::assertSame('vs-violation', $view->statusCssClass());

        self::assertCount(1, $view->signChecks);
        self::assertSame('four_eyes', $view->signChecks[0]['key']);
        self::assertFalse($view->signChecks[0]['satisfied']);
        self::assertSame(['Bob'], $view->signChecks[0]['missingValues']);
    }

    public function testOkStatusCssClass(): void
    {
        $view = DocumentCheckResultView::fromResult(new ProcessTemplateCheckResult([], [], []));
        self::assertSame('OK', $view->status);
        self::assertSame('vs-ok', $view->statusCssClass());
        self::assertFalse($view->hasActualSteps());
    }

    public function testUnavailable(): void
    {
        $view = DocumentCheckResultView::unavailable('boom');

        self::assertFalse($view->available);
        self::assertSame('boom', $view->error);
        self::assertSame('', $view->status);
        self::assertSame([], $view->expectedSteps);
    }
}
