<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessConformanceReason;
use App\Intelligence\Domain\ProcessGraph;
use App\Intelligence\Domain\ProcessGraphEdge;
use App\Intelligence\Domain\ProcessRunMeasurement;
use App\Intelligence\Domain\ProcessTemplate;

/** Evaluates observed transitions against the run's historical Soll revision. */
final readonly class ProcessRunConformanceEvaluator
{
    public function __construct(
        private ProcessTemplateGraphFactory $graphs = new ProcessTemplateGraphFactory(),
        private ProcessGraphObservationProjector $projector = new ProcessGraphObservationProjector()
    ) {
    }

    public function evaluate(ProcessRunMeasurement $run, ProcessTemplate $template): ProcessRunConformanceResult
    {
        if ($run->historicalTemplateVersion !== null && $run->historicalTemplateVersion !== $template->version) {
            return new ProcessRunConformanceResult(false, [ProcessConformanceReason::UnexpectedStep]);
        }
        $graph = $this->graphs->create($template);
        $reasons = [];
        foreach ($run->observedStepSequence as $visit) {
            if (!isset($graph->nodes[$visit->stepKey])) {
                $reasons[] = ProcessConformanceReason::UnexpectedStep;
            }
        }
        foreach ($run->observedTransitions as $transition) {
            if ($transition->fromStep === $transition->toStep) {
                continue; // Repetition is neutral unless the template says otherwise.
            }
            if ($template->transitions === [] && $template->decisionPoints === []) {
                continue; // Legacy templates without an explicit Soll relation are unconstrained.
            }
            $projection = $this->projector->project($graph, $template, $transition->fromStep, $transition->toStep);
            $implicitExpected = false;
            if ($projection->isUnexpected()) {
                foreach ($graph->edges as $edge) {
                    if ($edge->from === $transition->fromStep && $edge->to === $transition->toStep && $edge->style === ProcessGraphEdge::STYLE_IMPLICIT) {
                        $implicitExpected = true;
                        break;
                    }
                }
            }
            if ($projection->isUnexpected() && !$implicitExpected) {
                $reasons[] = ProcessConformanceReason::UnexpectedTransition;
            }
        }
        $reasons = array_values(array_unique($reasons, SORT_REGULAR));

        return new ProcessRunConformanceResult($reasons === [], $reasons);
    }
}
