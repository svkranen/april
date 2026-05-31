<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessGraphNodeMetrics;

final readonly class MermaidProcessGraphRenderOptions
{
    public const COMPAT_DEFAULT = 'default';
    public const COMPAT_OBSIDIAN = 'obsidian';
    public const VIEW_STRUCTURE = 'structure';
    public const VIEW_FLOW = 'flow';
    public const VIEW_DWELL = 'dwell';
    public const VIEW_DEVIATIONS = 'deviations';
    public const VIEW_COMBINED = 'combined';
    public const DWELL_METRIC_AVG = 'avg';
    public const DWELL_METRIC_MEDIAN = 'median';
    public const DWELL_METRIC_P95 = 'p95';
    public const DWELL_SCALE_RELATIVE_PERCENTILE = 'relative-percentile';
    public const DWELL_SCALE_FIXED_THRESHOLDS = 'fixed-thresholds';

    public function __construct(
        public bool $showDefaultOrder = false,
        public string $compatibility = self::COMPAT_DEFAULT,
        public string $view = self::VIEW_STRUCTURE,
        public string $dwellMetric = self::DWELL_METRIC_MEDIAN,
        public string $dwellScale = self::DWELL_SCALE_RELATIVE_PERCENTILE,
        public int $dwellBuckets = 8,
        public int $flowBuckets = 8,
        public bool $showNodeMetrics = false
    ) {
    }

    public function isObsidianCompatible(): bool
    {
        return $this->compatibility === self::COMPAT_OBSIDIAN;
    }

    public function showsFlowMetrics(): bool
    {
        return in_array($this->view, [self::VIEW_FLOW, self::VIEW_COMBINED], true);
    }

    public function showsNodeFlowMetrics(): bool
    {
        return $this->view === self::VIEW_FLOW;
    }

    public function showsDwellMetrics(): bool
    {
        return in_array($this->view, [self::VIEW_DWELL, self::VIEW_COMBINED], true);
    }

    public function showsDeviationMetrics(): bool
    {
        return in_array($this->view, [self::VIEW_DEVIATIONS, self::VIEW_COMBINED], true);
    }

    public function dwellSeconds(ProcessGraphNodeMetrics $metrics): float
    {
        return match ($this->dwellMetric) {
            self::DWELL_METRIC_AVG => $metrics->avgDwellSeconds,
            self::DWELL_METRIC_P95 => $metrics->p95DwellSeconds,
            default => $metrics->medianDwellSeconds,
        };
    }
}
