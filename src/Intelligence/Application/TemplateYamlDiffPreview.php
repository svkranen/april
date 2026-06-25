<?php

namespace App\Intelligence\Application;

/**
 * An illustrative, read-only YAML diff preview for a modelling suggestion. It shows
 * roughly how a YAML change *could* look so a human can judge a suggestion faster -
 * it is never applied, never written to disk and never validated against the actual
 * template file. Pure, Twig-friendly data.
 */
final readonly class TemplateYamlDiffPreview
{
    /** Unchanged context line (rendered with a leading space). */
    public const KIND_CONTEXT = 'context';
    /** Suggested new line (rendered with a leading "+"). */
    public const KIND_ADDITION = 'addition';

    /**
     * @param array<int, array{kind: string, text: string}> $lines
     */
    public function __construct(
        public string $caption,
        public array $lines
    ) {
    }

    /**
     * Build the preview for an observed transition that the Soll model does not
     * allow yet: it sketches the `transitions` entry one would add to permit it.
     * Returns null when either end is missing - then there is nothing to sketch.
     */
    public static function forTransitionDeviation(?string $from, ?string $to): ?self
    {
        $from = $from !== null ? trim($from) : '';
        $to = $to !== null ? trim($to) : '';
        if ($from === '' || $to === '') {
            return null;
        }

        return new self(
            'Mögliche YAML-Ergänzung (Vorschau – wird nicht gespeichert oder angewendet).',
            [
                ['kind' => self::KIND_CONTEXT, 'text' => 'transitions:'],
                ['kind' => self::KIND_ADDITION, 'text' => sprintf('  - from: %s', self::quote($from))],
                ['kind' => self::KIND_ADDITION, 'text' => sprintf('    to: %s', self::quote($to))],
            ]
        );
    }

    public function hasLines(): bool
    {
        return $this->lines !== [];
    }

    /**
     * Double-quote a scalar for the preview. Step keys can contain spaces, so we
     * always quote and escape the few characters that would break a double-quoted
     * YAML scalar. This is for display only - no YAML is parsed back.
     */
    private static function quote(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
