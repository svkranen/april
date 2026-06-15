<?php

namespace App\Intelligence\Application;

interface VisibilityCheckResultStore
{
    public function save(VisibilityCheckEvaluationResult $result, ?VisibilityCheckResultSaveContext $context = null): void;

    /**
     * @param array<int, VisibilityCheckEvaluationResult> $results
     */
    public function saveMany(array $results, ?VisibilityCheckResultSaveContext $context = null): int;
}
