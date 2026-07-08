<?php

namespace App\Wizard;

final readonly class NullWizardProgressStore implements WizardProgressStoreInterface
{
    public function get(string $wizardKey, ?string $stepKey = null): WizardProgressState
    {
        return new WizardProgressState(
            $wizardKey,
            $stepKey,
            WizardProgressState::STATUS_UNKNOWN,
            'Wizard progress is not persisted yet.'
        );
    }
}
