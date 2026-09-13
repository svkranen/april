<?php

namespace App\Intelligence\Domain;

enum KpiMeasurementReason: string
{
    case ProcessVersionNotSelected = KpiExclusionReason::PROCESS_VERSION_NOT_SELECTED;
    case MissingStart = 'missing_start';
    case MissingEnd = 'missing_end';
    case MissingBefore = 'missing_before';
    case MissingAfter = 'missing_after';
    case AmbiguousRun = 'ambiguous_run';
    case AmbiguousVisit = 'ambiguous_visit';
    case UnknownPhase = 'unknown_phase';
    case NoProcessVersion = KpiExclusionReason::NO_PROCESS_VERSION_DEFINED;
    case BeforeBaseline = KpiExclusionReason::BEFORE_FIRST_BASELINE;
    case StartedMidProcess = KpiExclusionReason::STARTED_MID_PROCESS;
    case CrossedVersionBoundary = KpiExclusionReason::CROSSED_VERSION_BOUNDARY;
}
