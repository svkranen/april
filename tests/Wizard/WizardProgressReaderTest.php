<?php

namespace App\Tests\Wizard;

use App\Wizard\NullWizardProgressStore;
use App\Wizard\WizardProgressReader;
use App\Wizard\WizardProgressState;
use App\Wizard\WizardProgressStoreInterface;
use PHPUnit\Framework\TestCase;

final class WizardProgressReaderTest extends TestCase
{
    public function testReadsUnknownWizardProgressFromNullStore(): void
    {
        $state = (new WizardProgressReader(new NullWizardProgressStore()))->read('first-insight');

        self::assertSame('first-insight', $state->wizardKey);
        self::assertNull($state->stepKey);
        self::assertSame(WizardProgressState::STATUS_UNKNOWN, $state->status);
        self::assertSame('Wizard progress is not persisted yet.', $state->message);
    }

    public function testReadsUnknownStepProgressFromNullStore(): void
    {
        $state = (new WizardProgressReader(new NullWizardProgressStore()))->read('first-insight', 'open_items');

        self::assertSame('first-insight', $state->wizardKey);
        self::assertSame('open_items', $state->stepKey);
        self::assertSame(WizardProgressState::STATUS_UNKNOWN, $state->status);
        self::assertSame('Wizard progress is not persisted yet.', $state->message);
    }

    public function testDelegatesToProgressStore(): void
    {
        $store = new class implements WizardProgressStoreInterface {
            public ?string $wizardKey = null;
            public ?string $stepKey = null;

            public function get(string $wizardKey, ?string $stepKey = null): WizardProgressState
            {
                $this->wizardKey = $wizardKey;
                $this->stepKey = $stepKey;

                return new WizardProgressState($wizardKey, $stepKey, 'unknown', 'Custom progress boundary.');
            }
        };

        $state = (new WizardProgressReader($store))->read('first-insight', 'open_journey');

        self::assertSame('first-insight', $store->wizardKey);
        self::assertSame('open_journey', $store->stepKey);
        self::assertSame('Custom progress boundary.', $state->message);
    }
}
