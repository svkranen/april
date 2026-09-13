<?php

namespace App\Intelligence\Domain;

enum ProcessConformanceReason: string
{
    case UnexpectedTransition = 'unexpected_transition';
    case UnexpectedStep = 'unexpected_step';
    case RequiredStepMissing = 'required_step_missing';
}
