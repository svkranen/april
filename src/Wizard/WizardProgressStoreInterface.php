<?php

namespace App\Wizard;

interface WizardProgressStoreInterface
{
    public function get(string $wizardKey, ?string $stepKey = null): WizardProgressState;
}
