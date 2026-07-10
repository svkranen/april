<?php

namespace App\Intelligence\Application;

use App\Intelligence\Domain\ProcessTemplateArrayFactory;
use App\Intelligence\Domain\ProcessTemplateSuggestionArraySerializer;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds a read-only template draft preview from a single document.
 *
 * Orchestration only - suggestion, serialization, parsing and graph rendering
 * are delegated to the same services the CLI command
 * intelligence:template:suggest-from-document uses, so UI and CLI produce
 * identical drafts. Nothing is written to disk or persisted.
 */
final class TemplateDraftPreviewBuilder
{
    public function __construct(
        private readonly TemplateSuggestionService $suggestionService,
        private readonly TemplateMermaidGraphBuilder $graphBuilder,
        private readonly ProcessTemplateSuggestionArraySerializer $serializer = new ProcessTemplateSuggestionArraySerializer()
    ) {
    }

    public function build(
        string $documentUuid,
        string $templateKey,
        string $scope,
        ?int $documentVersion = null
    ): TemplateDraftPreview {
        try {
            $suggestion = $this->suggestionService->suggestFromDocument(
                $documentUuid,
                $templateKey,
                $documentVersion,
                false,
                EventTimelineOrder::DEFAULT,
                $scope
            );
        } catch (InvalidArgumentException $exception) {
            return TemplateDraftPreview::error($documentUuid, $documentVersion, $templateKey, $scope, $exception->getMessage());
        }

        if ($suggestion === null) {
            return TemplateDraftPreview::notFound($documentUuid, $documentVersion, $templateKey, $scope);
        }

        // Identical dump settings to the CLI command so both emit the same YAML.
        $yaml = Yaml::dump($this->serializer->toArray($suggestion), 4, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

        // Round-trip through the regular parser/factory path: the preview must
        // reflect how the catalog would read this draft, not the in-memory object.
        $validationError = null;
        $template = null;
        try {
            $parsed = Yaml::parse($yaml);
            $template = ProcessTemplateArrayFactory::fromArray(is_array($parsed) ? $parsed : []);
        } catch (ParseException|InvalidArgumentException $exception) {
            $validationError = $exception->getMessage();
        }

        // Outside the catch: a graph-builder failure on a factory-valid template
        // is a programming error and must surface, not pose as a validation error.
        $mermaidCode = $template === null ? null : $this->graphBuilder->build($template, null);

        return TemplateDraftPreview::fromSuggestion(
            $documentUuid,
            $documentVersion,
            $templateKey,
            $scope,
            $yaml,
            $suggestion->warnings,
            $suggestion->suggestions,
            $validationError,
            $mermaidCode,
            count($suggestion->template->steps),
            count($suggestion->template->transitions)
        );
    }
}
