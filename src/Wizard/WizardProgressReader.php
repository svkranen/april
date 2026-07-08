<?php

namespace App\Wizard;

final readonly class WizardProgressReader
{
    public function __construct(
        private WizardProgressStoreInterface $store
    ) {
    }

    public function read(string $wizardKey, ?string $stepKey = null): WizardProgressState
    {
        return $this->store->get($wizardKey, $stepKey);
    }
}
