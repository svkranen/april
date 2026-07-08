<?php

namespace App\Tests\Wizard;

use App\Wizard\NullWizardProgressStore;
use App\Wizard\WizardProgressState;
use PHPUnit\Framework\TestCase;

final class NullWizardProgressStoreTest extends TestCase
{
    public function testReturnsUnknownWizardProgress(): void
    {
        $state = (new NullWizardProgressStore())->get('first-insight');

        self::assertSame('first-insight', $state->wizardKey);
        self::assertNull($state->stepKey);
        self::assertSame(WizardProgressState::STATUS_UNKNOWN, $state->status);
        self::assertSame('Wizard progress is not persisted yet.', $state->message);
    }

    public function testReturnsUnknownStepProgress(): void
    {
        $state = (new NullWizardProgressStore())->get('first-insight', 'open_items');

        self::assertSame('first-insight', $state->wizardKey);
        self::assertSame('open_items', $state->stepKey);
        self::assertSame(WizardProgressState::STATUS_UNKNOWN, $state->status);
        self::assertSame('Wizard progress is not persisted yet.', $state->message);
    }
}
