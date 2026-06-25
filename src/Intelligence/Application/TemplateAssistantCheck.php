<?php

namespace App\Intelligence\Application;

/**
 * One consistency-check category for the template assistant. Holds the offending
 * items (if any) plus the severity that applies when items are present. A check
 * with no items is shown as OK - so the page can give positive confirmation, not
 * only problems. Pure, Twig-friendly data.
 */
final readonly class TemplateAssistantCheck
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';

    /**
     * @param self::STATUS_WARNING|self::STATUS_ERROR $severityWhenPresent
     * @param array<int, string> $items offending entries; empty means the check passed
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $severityWhenPresent,
        public array $items,
        public string $okHint
    ) {
    }

    public function hasItems(): bool
    {
        return $this->items !== [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** OK when there are no offending items, otherwise the configured severity. */
    public function status(): string
    {
        return $this->hasItems() ? $this->severityWhenPresent : self::STATUS_OK;
    }
}
