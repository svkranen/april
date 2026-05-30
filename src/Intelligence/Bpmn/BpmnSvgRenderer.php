<?php

namespace App\Intelligence\Bpmn;

final class BpmnSvgRenderer
{
    /**
     * @return array<string, mixed>
     */
    private array $positions = [];

    /**
     * @var array<string, BpmnNodeMetrics>
     */
    private array $taskMetricsByStep = [];

    private ?string $endTaskId = null;

    public function render(BpmnProcessView $view, ?BpmnSvgRenderOptions $options = null): string
    {
        $options ??= new BpmnSvgRenderOptions();
        $this->taskMetricsByStep = $this->taskMetricsByStep($view);
        $this->positions = $this->layout($view, $options);
        $this->endTaskId = $this->findEndTaskId($view);
        $height = $options->compact ? 620 : 760;
        $svg = [];
        $svg[] = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="%s BPMN heatmap" data-view="%s" data-layout="%s">',
            $options->width,
            $height,
            $options->width,
            $height,
            $this->escape($view->templateKey),
            $this->escape($options->view),
            $this->escape($options->layout)
        );
        $svg[] = '<defs><marker id="arrow-expected" markerWidth="10" markerHeight="8" refX="9" refY="4" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L10,4 L0,8 z" fill="#6b7280"/></marker><marker id="arrow-observed" markerWidth="10" markerHeight="8" refX="9" refY="4" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L10,4 L0,8 z" fill="#2563eb"/></marker><marker id="arrow-unexpected" markerWidth="10" markerHeight="8" refX="9" refY="4" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L10,4 L0,8 z" fill="#dc2626"/></marker><marker id="arrow-missing" markerWidth="10" markerHeight="8" refX="9" refY="4" orient="auto" markerUnits="strokeWidth"><path d="M0,0 L10,4 L0,8 z" fill="#f59e0b"/></marker></defs>';
        $svg[] = '<rect x="0" y="0" width="100%" height="100%" fill="#ffffff"/>';
        $svg[] = sprintf('<text x="24" y="34" font-family="Arial, sans-serif" font-size="18" font-weight="700" fill="#111827">%s</text>', $this->escape($view->templateKey));

        $edges = $this->renderableEdges($view, $options);
        foreach ($edges as $edge) {
            if ($edge->status !== 'observed_unexpected') {
                continue;
            }
            $svg[] = $this->renderEdge($edge, $options);
        }

        foreach ($view->nodes as $node) {
            if ($node instanceof BpmnParallelGroupNode) {
                $svg[] = $this->renderParallelGroup($node);
            }
        }

        foreach ($view->nodes as $node) {
            if ($node instanceof BpmnTaskNode) {
                $svg[] = $this->renderTask($node);
            } elseif ($node instanceof BpmnGatewayNode) {
                $svg[] = $this->renderGateway($node);
            }
        }

        foreach ($edges as $edge) {
            if ($edge->status === 'observed_unexpected') {
                continue;
            }
            $svg[] = $this->renderEdge($edge, $options);
        }

        $svg[] = $this->renderLegend($options->width, $height, $options->view);
        $svg[] = '</svg>';

        return implode("\n", $svg)."\n";
    }

    private function renderTask(BpmnTaskNode $node): string
    {
        $box = $this->positions[$node->id];
        $fill = $this->taskFill($node->metrics);
        $stroke = $node->metrics->openDocuments > 0 ? '#dc2626' : '#9ca3af';
        $strokeWidth = $node->metrics->openDocuments > 0 || $node->metrics->intensity >= 0.8 ? 3 : 2;
        $isEnd = $node->id === $this->endTaskId;
        $label = $isEnd ? $node->label.' (Ende)' : ($node->required ? $node->label.' (required)' : $node->label);
        $metricLabel = sprintf('%dx · Ø %.1f min · open %d', $node->metrics->historicalCount, $node->metrics->avgDuration, $node->metrics->openDocuments);
        $endBadge = $isEnd
            ? sprintf(
                '<circle cx="%.1f" cy="%.1f" r="10" fill="#111827"/><text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial, sans-serif" font-size="8" font-weight="700" fill="#ffffff">Ende</text>',
                $box['x'] + $box['w'] - 18,
                $box['y'] + 18,
                $box['x'] + $box['w'] - 18,
                $box['y'] + 21
            )
            : '';

        return sprintf(
            '<g data-node-id="%s" data-open-documents="%d" data-heatmap-intensity="%.4f" data-node-role="%s"><rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="8" fill="%s" stroke="%s" stroke-width="%d"/>%s%s<text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" fill="#4b5563">%s</text></g>',
            $this->escape($node->id),
            $node->metrics->openDocuments,
            $node->metrics->intensity,
            $isEnd ? 'end' : 'task',
            $box['x'],
            $box['y'],
            $box['w'],
            $box['h'],
            $fill,
            $stroke,
            $strokeWidth,
            $this->renderWrappedText($label, $box['x'] + $box['w'] / 2, $box['y'] + 23, 12, 2, true),
            $endBadge,
            $box['x'] + $box['w'] / 2,
            $box['y'] + $box['h'] - 12,
            $this->escape($metricLabel)
        );
    }

    private function renderGateway(BpmnGatewayNode $node): string
    {
        $box = $this->positions[$node->id];
        $cx = $box['x'] + $box['w'] / 2;
        $cy = $box['y'] + $box['h'] / 2;
        $points = sprintf('%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f', $cx, $box['y'], $box['x'] + $box['w'], $cy, $cx, $box['y'] + $box['h'], $box['x'], $cy);

        return sprintf(
            '<g data-node-id="%s"><polygon points="%s" fill="#fef3c7" stroke="#f59e0b" stroke-width="2"/>%s</g>',
            $this->escape($node->id),
            $points,
            $this->renderWrappedText($this->shortGatewayLabel($node->decisionPointKey), $cx, $cy - 2, 11, 2, true)
        );
    }

    private function renderParallelGroup(BpmnParallelGroupNode $node): string
    {
        $box = $this->positions[$node->id];
        $fill = $this->taskFill($node->metrics);
        $maxOpenDocuments = 0;
        foreach ($node->requiredStepKeys as $stepKey) {
            $maxOpenDocuments = max($maxOpenDocuments, $this->taskMetricsByStep[$stepKey]->openDocuments ?? 0);
        }
        $metricsLabel = sprintf(
            'documents %d · Ø %.1f min · max open %d',
            $node->metrics->historicalCount,
            $node->metrics->avgDuration,
            $maxOpenDocuments
        );

        return sprintf(
            '<g data-node-id="%s" data-open-documents="%d" data-heatmap-intensity="%.4f"><rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="12" fill="%s" fill-opacity="0.55" stroke="#2563eb" stroke-width="2" stroke-dasharray="6 4"/><text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial, sans-serif" font-size="12" font-weight="700" fill="#111827">%s</text><text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" fill="#4b5563">%s</text><text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" font-weight="700" fill="#1d4ed8">%s</text></g>',
            $this->escape($node->id),
            $node->metrics->openDocuments,
            $node->metrics->intensity,
            $box['x'],
            $box['y'],
            $box['w'],
            $box['h'],
            $fill,
            $box['x'] + $box['w'] / 2,
            $box['y'] + 28,
            $this->escape('Parallel: '.$node->parallelGroupKey),
            $box['x'] + $box['w'] / 2,
            $box['y'] + 48,
            $this->escape(implode(', ', $node->requiredStepKeys)),
            $box['x'] + $box['w'] / 2,
            $box['y'] + $box['h'] - 18,
            $this->escape($metricsLabel)
        );
    }

    private function renderEdge(BpmnTransitionEdge $edge, BpmnSvgRenderOptions $options): string
    {
        if (!isset($this->positions[$edge->fromNodeId], $this->positions[$edge->toNodeId])) {
            return '';
        }

        $fromBox = $this->positions[$edge->fromNodeId];
        $toBox = $this->positions[$edge->toNodeId];
        $from = $this->center($fromBox);
        $to = $this->center($toBox);
        $style = $this->edgeStyle($edge, $options->view);
        $label = $this->edgeLabel($edge, $options->view);
        $labelX = ($from['x'] + $to['x']) / 2;
        $labelY = ($from['y'] + $to['y']) / 2 - 8;
        $marker = $this->markerId($edge, $options->view);
        $path = $edge->status === 'observed_unexpected'
            ? $this->unexpectedPath($edge, $from, $to)
            : ($options->layout === 'process'
                ? $this->processPath($fromBox, $toBox)
                : sprintf('M %.1f %.1f L %.1f %.1f', $from['x'], $from['y'], $to['x'], $to['y']));

        $text = $label === ''
            ? ''
            : sprintf(
                '<text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial, sans-serif" font-size="10" fill="%s">%s</text>',
                $labelX,
                $labelY,
                $style['stroke'],
                $this->escape($label)
            );

        return sprintf(
            '<g data-edge-source="%s" data-edge-status="%s"><path d="%s" fill="none" stroke="%s" stroke-width="%.1f" stroke-dasharray="%s" marker-end="url(#%s)"/>%s</g>',
            $this->escape($edge->source),
            $this->escape($edge->status),
            $path,
            $style['stroke'],
            $style['width'],
            $style['dash'],
            $marker,
            $text
        );
    }

    /**
     * @return array<int, BpmnTransitionEdge>
     */
    private function renderableEdges(BpmnProcessView $view, BpmnSvgRenderOptions $options): array
    {
        return array_values(array_filter(
            $view->edges,
            fn (BpmnTransitionEdge $edge): bool => $this->shouldRenderEdge($edge, $options)
        ));
    }

    private function shouldRenderEdge(BpmnTransitionEdge $edge, BpmnSvgRenderOptions $options): bool
    {
        if ($edge->source === 'required_step') {
            return false;
        }

        if ($edge->source === 'parallel_group' && str_starts_with($edge->fromNodeId, 'parallel:')) {
            return false;
        }

        if ($options->layout === 'process' && in_array($options->view, ['summary', 'bottleneck'], true) && $edge->source === 'parallel_group') {
            return false;
        }

        return match ($options->view) {
            'summary' => $edge->source !== 'observed' && $edge->status !== 'observed_unexpected',
            'bottleneck' => $edge->source === 'decision_point',
            'expected' => $edge->source !== 'observed' && $edge->status !== 'observed_unexpected',
            'observed' => $edge->observedCount > 0 || $edge->percentage > 0.0,
            'deviations' => $edge->status === 'missing_expected'
                || ($edge->status === 'observed_unexpected' && $edge->observedCount >= $options->minUnexpectedCount),
            default => $edge->status !== 'observed_unexpected'
                || $edge->observedCount >= $options->minUnexpectedCount,
        };
    }

    /**
     * @return array<string, array{x: float, y: float, w: float, h: float}>
     */
    private function layout(BpmnProcessView $view, BpmnSvgRenderOptions $options): array
    {
        if ($options->layout === 'process') {
            return $this->processLayout($view, $options);
        }

        return $this->graphLayout($view, $options);
    }

    /**
     * @return array<string, array{x: float, y: float, w: float, h: float}>
     */
    private function graphLayout(BpmnProcessView $view, BpmnSvgRenderOptions $options): array
    {
        $positions = [];
        $tasks = array_values(array_filter($view->nodes, static fn (object $node): bool => $node instanceof BpmnTaskNode));
        $parallelGroups = array_values(array_filter($view->nodes, static fn (object $node): bool => $node instanceof BpmnParallelGroupNode));
        $parallelStepKeys = [];
        foreach ($parallelGroups as $group) {
            foreach ($group->requiredStepKeys as $stepKey) {
                $parallelStepKeys[$stepKey] = true;
            }
        }

        $requiredTasks = array_values(array_filter($tasks, static fn (BpmnTaskNode $task): bool => $task->required));
        $startTask = $requiredTasks[0] ?? $tasks[0] ?? null;
        $endTask = $requiredTasks[count($requiredTasks) - 1] ?? ($tasks[count($tasks) - 1] ?? null);
        $taskWidth = 178.0;
        $taskHeight = 82.0;

        if ($startTask !== null) {
            $positions[$startTask->id] = ['x' => 48.0, 'y' => 240.0, 'w' => $taskWidth, 'h' => $taskHeight];
        }

        if ($endTask !== null && $endTask->id !== $startTask?->id) {
            $positions[$endTask->id] = ['x' => $options->width - $taskWidth - 48.0, 'y' => 240.0, 'w' => $taskWidth, 'h' => $taskHeight];
        }

        $parallelIndex = 0;
        foreach ($parallelGroups as $group) {
            $groupWidth = 260.0;
            $groupHeight = max(150.0, 56.0 + count($group->requiredStepKeys) * 90.0);
            $groupX = min(max(330.0 + $parallelIndex * 300.0, 280.0), max(280.0, $options->width - $groupWidth - 260.0));
            $groupY = 360.0;
            $positions[$group->id] = ['x' => $groupX, 'y' => $groupY, 'w' => $groupWidth, 'h' => $groupHeight];

            foreach (array_values($group->requiredStepKeys) as $memberIndex => $stepKey) {
                $positions['task:'.$stepKey] = [
                    'x' => $groupX + 36.0,
                    'y' => $groupY + 42.0 + $memberIndex * 88.0,
                    'w' => $groupWidth - 72.0,
                    'h' => 72.0,
                ];
            }

            ++$parallelIndex;
        }

        $laneTasks = array_values(array_filter(
            $tasks,
            static fn (BpmnTaskNode $task): bool => !isset($positions[$task->id]) && !isset($parallelStepKeys[$task->stepKey])
        ));
        $laneY = [88.0, 230.0, 276.0];
        $laneX = 300.0;
        foreach ($laneTasks as $index => $task) {
            $positions[$task->id] = [
                'x' => min($laneX + $index * 230.0, max(260.0, $options->width - $taskWidth - 260.0)),
                'y' => $laneY[$index % count($laneY)],
                'w' => $taskWidth,
                'h' => $taskHeight,
            ];
        }

        $gatewayOffset = 0;
        foreach ($view->nodes as $node) {
            if ($node instanceof BpmnGatewayNode) {
                $anchor = $node->afterStepKey !== null && isset($positions['task:'.$node->afterStepKey])
                    ? $positions['task:'.$node->afterStepKey]
                    : ['x' => 80 + $gatewayOffset * 180, 'y' => 230, 'w' => $taskWidth, 'h' => $taskHeight];
                $positions[$node->id] = [
                    'x' => $anchor['x'] + $anchor['w'] + 42.0,
                    'y' => $anchor['y'] + 8.0 + $gatewayOffset * 18.0,
                    'w' => 92.0,
                    'h' => 72.0,
                ];
                ++$gatewayOffset;
            }
        }

        return $positions;
    }

    /**
     * @return array<string, array{x: float, y: float, w: float, h: float}>
     */
    private function processLayout(BpmnProcessView $view, BpmnSvgRenderOptions $options): array
    {
        $positions = [];
        $tasks = [];
        $gateways = [];
        $parallelGroups = [];
        $parallelStepKeys = [];
        $taskWidth = 178.0;
        $taskHeight = 82.0;
        $gatewayWidth = 92.0;
        $gatewayHeight = 72.0;

        foreach ($view->nodes as $node) {
            if ($node instanceof BpmnTaskNode) {
                $tasks[$node->id] = $node;
                continue;
            }

            if ($node instanceof BpmnGatewayNode) {
                $gateways[$node->id] = $node;
                continue;
            }

            if ($node instanceof BpmnParallelGroupNode) {
                $parallelGroups[$node->id] = $node;
                foreach ($node->requiredStepKeys as $stepKey) {
                    $parallelStepKeys[$stepKey] = true;
                }
            }
        }

        $requiredTasks = array_values(array_filter($tasks, static fn (BpmnTaskNode $task): bool => $task->required));
        $allTasks = array_values($tasks);
        $startTask = $requiredTasks[0] ?? $allTasks[0] ?? null;
        $endTask = $requiredTasks[count($requiredTasks) - 1] ?? ($allTasks[count($allTasks) - 1] ?? null);
        $startY = 250.0;
        $branchY = [86.0, 220.0, 360.0, 490.0];
        $left = 48.0;
        $gatewayX = min(300.0, max(250.0, $options->width - 850.0));
        $branchX = min(510.0, max(430.0, $options->width - 620.0));
        $parallelWidth = 288.0;
        $parallelX = min(max($branchX + 150.0, 650.0), max(360.0, $options->width - $parallelWidth - 250.0));
        $endX = max($left + 600.0, $options->width - $taskWidth - 48.0);
        $endY = 382.0;

        if ($startTask !== null) {
            $positions[$startTask->id] = ['x' => $left, 'y' => $startY, 'w' => $taskWidth, 'h' => $taskHeight];
        }

        if ($endTask !== null && $endTask->id !== $startTask?->id) {
            $positions[$endTask->id] = ['x' => $endX, 'y' => $endY, 'w' => $taskWidth, 'h' => $taskHeight];
        }

        $parallelIndex = 0;
        foreach ($parallelGroups as $group) {
            $groupHeight = max(166.0, 58.0 + count($group->requiredStepKeys) * 86.0);
            $groupY = 338.0 + $parallelIndex * 28.0;
            $positions[$group->id] = ['x' => $parallelX, 'y' => $groupY, 'w' => $parallelWidth, 'h' => $groupHeight];

            foreach (array_values($group->requiredStepKeys) as $memberIndex => $stepKey) {
                $positions['task:'.$stepKey] = [
                    'x' => $parallelX + 44.0,
                    'y' => $groupY + 48.0 + $memberIndex * 84.0,
                    'w' => $parallelWidth - 88.0,
                    'h' => 70.0,
                ];
            }
            ++$parallelIndex;
        }

        $decisionRuleTargets = [];
        foreach ($view->edges as $edge) {
            if ($edge->source !== 'decision_rule') {
                continue;
            }

            $decisionRuleTargets[$edge->fromNodeId][] = $edge->toNodeId;
        }

        $placedBranchTargets = [];
        foreach (array_values($gateways) as $gatewayIndex => $gateway) {
            $anchorId = $gateway->afterStepKey !== null ? 'task:'.$gateway->afterStepKey : null;
            $anchor = $anchorId !== null && isset($positions[$anchorId])
                ? $positions[$anchorId]
                : ['x' => $left, 'y' => $startY, 'w' => $taskWidth, 'h' => $taskHeight];
            $x = $anchor['x'] + $anchor['w'] + 58.0;
            $y = $anchor['y'] + 5.0;

            if ($gatewayIndex === 0 && $startTask !== null && $gateway->afterStepKey === $startTask->stepKey) {
                $x = $gatewayX;
                $y = $startY + 5.0;
            }

            $positions[$gateway->id] = ['x' => $x, 'y' => $y, 'w' => $gatewayWidth, 'h' => $gatewayHeight];

            $targets = array_values(array_unique($decisionRuleTargets[$gateway->id] ?? []));
            foreach ($targets as $targetIndex => $targetId) {
                if (!isset($tasks[$targetId]) || isset($positions[$targetId]) || isset($parallelStepKeys[$tasks[$targetId]->stepKey])) {
                    continue;
                }

                $targetY = $gatewayIndex === 0
                    ? ($branchY[$targetIndex] ?? (96.0 + $targetIndex * 124.0))
                    : max(88.0, $anchor['y'] - 116.0 + $targetIndex * 126.0);
                $positions[$targetId] = [
                    'x' => $gatewayIndex === 0 ? $branchX : $anchor['x'] + $anchor['w'] + 210.0,
                    'y' => $targetY,
                    'w' => $taskWidth,
                    'h' => $taskHeight,
                ];
                $placedBranchTargets[$targetId] = true;
            }
        }

        $remaining = array_values(array_filter(
            $tasks,
            static fn (BpmnTaskNode $task): bool => !isset($positions[$task->id]) && !isset($placedBranchTargets[$task->id])
        ));
        foreach ($remaining as $index => $task) {
            $positions[$task->id] = [
                'x' => min($branchX + $index * 220.0, max($branchX, $options->width - $taskWidth - 260.0)),
                'y' => 92.0 + ($index % 4) * 118.0,
                'w' => $taskWidth,
                'h' => $taskHeight,
            ];
        }

        return $positions;
    }

    /**
     * @param array{x: float, y: float, w: float, h: float} $box
     * @return array{x: float, y: float}
     */
    private function center(array $box): array
    {
        return [
            'x' => $box['x'] + $box['w'] / 2,
            'y' => $box['y'] + $box['h'] / 2,
        ];
    }

    /**
     * @return array{stroke: string, width: float, dash: string}
     */
    private function edgeStyle(BpmnTransitionEdge $edge, string $view): array
    {
        if (in_array($view, ['summary', 'bottleneck'], true)) {
            return [
                'stroke' => '#6b7280',
                'width' => 1.6,
                'dash' => '',
            ];
        }

        return match ($edge->status) {
            'observed_allowed' => [
                'stroke' => '#2563eb',
                'width' => max(2.5, 2.5 + $edge->intensity * 4),
                'dash' => '',
            ],
            'observed_unexpected' => [
                'stroke' => '#dc2626',
                'width' => max(2.0, 2.0 + $edge->intensity * 3),
                'dash' => '7 5',
            ],
            'missing_expected' => [
                'stroke' => '#f59e0b',
                'width' => 2.5,
                'dash' => '5 4',
            ],
            default => [
                'stroke' => '#6b7280',
                'width' => 1.5,
                'dash' => '',
            ],
        };
    }

    private function markerId(BpmnTransitionEdge $edge, string $view): string
    {
        if (in_array($view, ['summary', 'bottleneck'], true)) {
            return 'arrow-expected';
        }

        return match ($edge->status) {
            'observed_allowed' => 'arrow-observed',
            'observed_unexpected' => 'arrow-unexpected',
            'missing_expected' => 'arrow-missing',
            default => 'arrow-expected',
        };
    }

    /**
     * @param array{x: float, y: float} $from
     * @param array{x: float, y: float} $to
     */
    private function unexpectedPath(BpmnTransitionEdge $edge, array $from, array $to): string
    {
        $hash = abs(crc32($edge->id));
        $arc = 80.0 + ($hash % 5) * 28.0;
        $direction = ($hash % 2) === 0 ? -1.0 : 1.0;
        $controlY = min($from['y'], $to['y']) - $arc;

        if (abs($from['x'] - $to['x']) < 80.0) {
            $controlX = $from['x'] + 120.0 * $direction;

            return sprintf(
                'M %.1f %.1f C %.1f %.1f, %.1f %.1f, %.1f %.1f',
                $from['x'],
                $from['y'],
                $controlX,
                $controlY,
                $controlX,
                $to['y'] - $arc / 2,
                $to['x'],
                $to['y']
            );
        }

        return sprintf(
            'M %.1f %.1f C %.1f %.1f, %.1f %.1f, %.1f %.1f',
            $from['x'],
            $from['y'],
            $from['x'],
            $controlY,
            $to['x'],
            $controlY,
            $to['x'],
            $to['y']
        );
    }

    /**
     * @param array{x: float, y: float, w: float, h: float} $from
     * @param array{x: float, y: float, w: float, h: float} $to
     */
    private function processPath(array $from, array $to): string
    {
        $startX = $from['x'] + $from['w'];
        $startY = $from['y'] + $from['h'] / 2;
        $endX = $to['x'];
        $endY = $to['y'] + $to['h'] / 2;

        if ($endX >= $startX) {
            $midX = ($startX + $endX) / 2;

            return sprintf(
                'M %.1f %.1f H %.1f V %.1f H %.1f',
                $startX,
                $startY,
                $midX,
                $endY,
                $endX
            );
        }

        $controlX = max($startX + 60.0, $from['x'] + $from['w'] + 80.0);

        return sprintf(
            'M %.1f %.1f C %.1f %.1f, %.1f %.1f, %.1f %.1f',
            $startX,
            $startY,
            $controlX,
            $startY,
            $controlX,
            $endY,
            $endX,
            $endY
        );
    }

    private function taskFill(BpmnNodeMetrics $metrics): string
    {
        if ($metrics->openDocuments > 0) {
            return $metrics->openDocuments >= 10 ? '#fecaca' : '#fee2e2';
        }

        if ($metrics->intensity >= 0.8) {
            return '#fdba74';
        }

        if ($metrics->intensity >= 0.5) {
            return '#fed7aa';
        }

        return '#f9fafb';
    }

    private function edgeLabel(BpmnTransitionEdge $edge, string $view): string
    {
        if ($view === 'bottleneck') {
            return '';
        }

        if ($view === 'summary') {
            return $edge->conditionLabel !== null ? $this->shortConditionLabel($edge->conditionLabel, true) : '';
        }

        if ($view === 'expected' && $edge->conditionLabel !== null) {
            return $this->shortConditionLabel($edge->conditionLabel);
        }

        if ($edge->observedCount > 0 || $edge->percentage > 0.0) {
            return sprintf('%dx %.0f%%', $edge->observedCount, $edge->percentage);
        }

        return $edge->conditionLabel !== null ? $this->shortConditionLabel($edge->conditionLabel) : $edge->status;
    }

    private function shortConditionLabel(string $label, bool $symbolOperators = false): string
    {
        if ($label === 'else') {
            return $label;
        }

        if (preg_match('/^(.+?)\s+(eq|neq|gt|gte|lt|lte|in|exists)\s+(.+)$/', $label, $matches) !== 1) {
            return $label;
        }

        $operator = $matches[2];
        $value = trim($matches[3], '"');

        if (str_starts_with($value, 'RE - ')) {
            $value = 'RE-'.substr($value, 5);
        }

        if ($operator === 'eq') {
            return $value;
        }

        if ($symbolOperators) {
            $operator = match ($operator) {
                'gt' => '>',
                'gte' => '>=',
                'lt' => '<',
                'lte' => '<=',
                default => $operator,
            };
        }

        return sprintf('%s %s', $operator, $value);
    }

    private function shortGatewayLabel(string $decisionPointKey): string
    {
        $label = str_replace(['route_after_', 'freigabe_ab_'], ['', '> '], $decisionPointKey);
        $label = str_replace('_', ' ', $label);

        return trim($label);
    }

    private function findEndTaskId(BpmnProcessView $view): ?string
    {
        $requiredTaskId = null;
        $lastTaskId = null;

        foreach ($view->nodes as $node) {
            if (!$node instanceof BpmnTaskNode) {
                continue;
            }

            $lastTaskId = $node->id;
            if ($node->required) {
                $requiredTaskId = $node->id;
            }
        }

        return $requiredTaskId ?? $lastTaskId;
    }

    /**
     * @return array<string, BpmnNodeMetrics>
     */
    private function taskMetricsByStep(BpmnProcessView $view): array
    {
        $metrics = [];
        foreach ($view->nodes as $node) {
            if ($node instanceof BpmnTaskNode) {
                $metrics[$node->stepKey] = $node->metrics;
            }
        }

        return $metrics;
    }

    private function renderLegend(int $width, int $height, string $view): string
    {
        $x = max(20, $width - 470);
        $y = $height - 58;

        if (in_array($view, ['summary', 'bottleneck'], true)) {
            return sprintf(
                '<g data-legend="true"><rect x="%d" y="%d" width="330" height="38" rx="8" fill="#ffffff" stroke="#e5e7eb"/><line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#6b7280" stroke-width="2"/><text x="%d" y="%d" font-family="Arial, sans-serif" font-size="11">expected flow</text><rect x="%d" y="%d" width="34" height="16" rx="4" fill="#fee2e2" stroke="#dc2626"/><text x="%d" y="%d" font-family="Arial, sans-serif" font-size="11">task heatmap / backlog</text></g>',
                $x,
                $y,
                $x + 18,
                $y + 20,
                $x + 54,
                $y + 20,
                $x + 62,
                $y + 24,
                $x + 154,
                $y + 11,
                $x + 196,
                $y + 24
            );
        }

        return sprintf(
            '<g data-legend="true"><rect x="%d" y="%d" width="440" height="38" rx="8" fill="#ffffff" stroke="#e5e7eb"/><line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#6b7280" stroke-width="2"/><text x="%d" y="%d" font-family="Arial, sans-serif" font-size="11">expected</text><line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#2563eb" stroke-width="4"/><text x="%d" y="%d" font-family="Arial, sans-serif" font-size="11">observed</text><line x1="%d" y1="%d" x2="%d" y2="%d" stroke="#dc2626" stroke-width="3" stroke-dasharray="7 5"/><text x="%d" y="%d" font-family="Arial, sans-serif" font-size="11">unexpected</text></g>',
            $x,
            $y,
            $x + 18,
            $y + 20,
            $x + 54,
            $y + 20,
            $x + 62,
            $y + 24,
            $x + 138,
            $y + 20,
            $x + 174,
            $y + 20,
            $x + 182,
            $y + 24,
            $x + 258,
            $y + 20,
            $x + 294,
            $y + 20,
            $x + 302,
            $y + 24
        );
    }

    private function renderWrappedText(string $text, float $x, float $y, int $fontSize, int $maxLines, bool $bold = false): string
    {
        $lines = $this->wrapText($text, 24, $maxLines);
        $spans = [];
        foreach ($lines as $index => $line) {
            $spans[] = sprintf(
                '<tspan x="%.1f" dy="%s">%s</tspan>',
                $x,
                $index === 0 ? '0' : '1.15em',
                $this->escape($line)
            );
        }

        return sprintf(
            '<text x="%.1f" y="%.1f" text-anchor="middle" font-family="Arial, sans-serif" font-size="%d" font-weight="%s" fill="#111827">%s</text>',
            $x,
            $y,
            $fontSize,
            $bold ? '700' : '400',
            implode('', $spans)
        );
    }

    /**
     * @return array<int, string>
     */
    private function wrapText(string $text, int $maxChars, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if (strlen($candidate) <= $maxChars) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $lines[] = $word;
            }

            if (count($lines) >= $maxLines) {
                break;
            }
        }

        if ($current !== '' && count($lines) < $maxLines) {
            $lines[] = $current;
        }

        return $lines === [] ? [''] : $lines;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
