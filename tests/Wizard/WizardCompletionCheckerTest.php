<?php

namespace App\Tests\Wizard;

use App\Wizard\WizardCompletionChecker;
use App\Wizard\WizardCompletionCheckResult;
use App\Wizard\WizardStepDefinition;
use PHPUnit\Framework\TestCase;

final class WizardCompletionCheckerTest extends TestCase
{
    public function testRouteVisitedCompletionIsUnknown(): void
    {
        $result = $this->check(['type' => 'route_visited', 'route' => 'app_templates_index']);

        self::assertSame('route_visited', $result->type);
        self::assertSame(WizardCompletionCheckResult::STATUS_UNKNOWN, $result->status);
        self::assertSame('Route visits are not tracked yet.', $result->message);
    }

    public function testStepAcknowledgedCompletionIsUnknown(): void
    {
        $result = $this->check(['type' => 'step_acknowledged']);

        self::assertSame('step_acknowledged', $result->type);
        self::assertSame(WizardCompletionCheckResult::STATUS_UNKNOWN, $result->status);
        self::assertSame('No Wizard runtime or persistence exists yet.', $result->message);
    }

    public function testManualCompletionIsUnknown(): void
    {
        $result = $this->check(['type' => 'manual']);

        self::assertSame('manual', $result->type);
        self::assertSame(WizardCompletionCheckResult::STATUS_UNKNOWN, $result->status);
        self::assertSame('Manual completion is not executable in the MVP.', $result->message);
    }

    public function testUnknownCompletionTypeIsWarning(): void
    {
        $result = $this->check(['type' => 'finding_present']);

        self::assertSame('finding_present', $result->type);
        self::assertSame(WizardCompletionCheckResult::STATUS_WARNING, $result->status);
        self::assertStringContainsString('Unsupported completion type "finding_present"', $result->message);
    }

    public function testStepWithoutCompletionIsUnknown(): void
    {
        $results = (new WizardCompletionChecker())->checkStep(new WizardStepDefinition('welcome', 'Welcome', []));

        self::assertCount(1, $results);
        self::assertSame('none', $results[0]->type);
        self::assertSame(WizardCompletionCheckResult::STATUS_UNKNOWN, $results[0]->status);
        self::assertSame('No completion rule is defined for this step.', $results[0]->message);
    }

    /**
     * @param array<string, mixed> $completion
     */
    private function check(array $completion): WizardCompletionCheckResult
    {
        $results = (new WizardCompletionChecker())->checkStep(new WizardStepDefinition('step', 'Step', [
            'completion' => $completion,
        ]));

        return $results[0];
    }
}
