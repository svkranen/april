<?php

namespace App\Intelligence\Domain;

final class KpiExclusionReason
{
    public const NO_PROCESS_VERSION_DEFINED = 'no_process_version_defined';
    public const BEFORE_FIRST_BASELINE = 'before_first_baseline';
    public const STARTED_MID_PROCESS = 'started_mid_process';
    public const CROSSED_VERSION_BOUNDARY = 'crossed_version_boundary';

    public const PROCESS_VERSION_NOT_SELECTED = 'process_version_not_selected';

    private function __construct()
    {
    }
}
