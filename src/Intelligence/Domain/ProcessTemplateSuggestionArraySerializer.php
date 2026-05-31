<?php

namespace App\Intelligence\Domain;

final class ProcessTemplateSuggestionArraySerializer
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(ProcessTemplateSuggestionResult $result): array
    {
        $template = $result->template;
        $data = [
            'key' => $template->key,
            'version' => $template->version,
            'steps' => array_map(
                static fn (ProcessTemplateStep $step): array => self::stepToArray($step),
                $template->steps
            ),
            'transitions' => $this->transitionsToArray($result),
            'context_profile' => [
                'required' => $template->contextProfileRequiredFields,
            ],
        ];

        if ($template->name !== null) {
            $data = ['key' => $data['key'], 'name' => $template->name] + array_slice($data, 1, null, true);
        }

        if ($template->requiredStepKeys !== []) {
            $data['required_steps'] = $template->requiredStepKeys;
        }

        if ($result->usedDocumentUuids !== []) {
            $data['documents_used'] = count($result->usedDocumentUuids);
            $data['document_uuids'] = $result->usedDocumentUuids;
            $data['warnings'] = [];
        }

        if ($template->fieldMappings !== []) {
            $data['field_mapping'] = array_map(
                static fn (ProcessTemplateFieldMapping $mapping): array => self::fieldMappingToArray($mapping),
                $template->fieldMappings
            );
        }

        if ($template->contextPolicy !== null) {
            $data['context_policy'] = [
                'snapshot' => [
                    'max_delay_seconds' => $template->contextPolicy->snapshotMaxDelaySeconds,
                    'stale_behavior' => $template->contextPolicy->snapshotStaleBehavior,
                ],
            ];
        }

        if ($result->warnings !== []) {
            $data['warnings'] = array_map(
                static fn (ProcessTemplateSuggestionWarning $warning): array => self::warningToArray($warning),
                $result->warnings
            );
        }

        if ($template->parallelGroups !== []) {
            $data['parallel_groups'] = array_map(
                fn (ProcessTemplateParallelGroup $parallelGroup): array => $this->parallelGroupToArray($parallelGroup, $result->suggestions),
                $template->parallelGroups
            );
        }

        if ($result->suggestions !== []) {
            $data['suggestions'] = array_map(
                static fn (ProcessTemplateSuggestionNote $suggestion): array => self::suggestionToArray($suggestion),
                $result->suggestions
            );
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    private static function fieldMappingToArray(ProcessTemplateFieldMapping $mapping): array
    {
        $data = [
            'source' => $mapping->source,
        ];
        if ($mapping->tagName !== null) {
            $data['tag_name'] = $mapping->tagName;
        }
        if ($mapping->tagId !== null) {
            $data['tag_id'] = $mapping->tagId;
        }
        if ($mapping->valueType !== null) {
            $data['value_type'] = $mapping->valueType;
        }
        if ($mapping->stability !== null) {
            $data['stability'] = $mapping->stability;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function stepToArray(ProcessTemplateStep $step): array
    {
        $data = ['key' => $step->key];
        if ($step->name !== null) {
            $data['name'] = $step->name;
        }
        if ($step->type !== 'normal') {
            $data['type'] = $step->type;
        }

        return $data;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function transitionsToArray(ProcessTemplateSuggestionResult $result): array
    {
        if ($result->transitionSuggestions !== []) {
            return array_map(
                static fn (SuggestedTransition $transition): array => self::suggestedTransitionToArray($transition),
                $result->transitionSuggestions
            );
        }

        return array_map(
            static function (ProcessTemplateTransition $transition): array {
                $data = ['from' => $transition->from];
                if ($transition->to !== null) {
                    $data['to'] = $transition->to;
                }
                if ($transition->toParallelGroup !== null) {
                    $data['to_parallel_group'] = $transition->toParallelGroup;
                }

                return $data;
            },
            $result->template->transitions
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function suggestedTransitionToArray(SuggestedTransition $transition): array
    {
        $data = [
            'from' => $transition->from,
            'to' => $transition->to,
        ];
        if ($transition->observedCount !== null) {
            $data['observed_count'] = $transition->observedCount;
        }
        if ($transition->confidence !== null) {
            $data['confidence'] = $transition->confidence;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function warningToArray(ProcessTemplateSuggestionWarning $warning): array
    {
        $data = [
            'type' => $warning->type,
            'message' => $warning->message,
        ];
        if ($warning->documentUuids !== []) {
            $data['document_uuids'] = $warning->documentUuids;
        }

        return $data;
    }

    /**
     * @param array<int, ProcessTemplateSuggestionNote> $suggestions
     * @return array<string, mixed>
     */
    private function parallelGroupToArray(ProcessTemplateParallelGroup $parallelGroup, array $suggestions): array
    {
        $data = [
            'key' => $parallelGroup->key,
        ];
        if ($parallelGroup->after !== null) {
            $data['after'] = $parallelGroup->after;
        }
        $data += [
            'required_steps' => $parallelGroup->requiredStepKeys,
            'order' => $parallelGroup->order,
        ];
        if ($parallelGroup->nextStepKey !== null) {
            $data['next'] = $parallelGroup->nextStepKey;
        }

        $suggestion = $this->suggestionForParallelGroup($parallelGroup->key, $suggestions);
        if ($suggestion !== null) {
            if ($suggestion->confidence !== null) {
                $data['confidence'] = $suggestion->confidence;
            }
            $data['reason'] = $suggestion->message;
            if ($suggestion->documentUuids !== []) {
                $data['document_uuids'] = $suggestion->documentUuids;
            }
        }

        return $data;
    }

    /**
     * @param array<int, ProcessTemplateSuggestionNote> $suggestions
     */
    private function suggestionForParallelGroup(string $parallelGroupKey, array $suggestions): ?ProcessTemplateSuggestionNote
    {
        foreach ($suggestions as $suggestion) {
            if ($suggestion->parallelGroupKey === $parallelGroupKey) {
                return $suggestion;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function suggestionToArray(ProcessTemplateSuggestionNote $suggestion): array
    {
        $data = [
            'type' => $suggestion->type,
        ];
        if ($suggestion->parallelGroupKey !== null) {
            $data['parallel_group_key'] = $suggestion->parallelGroupKey;
        }
        if ($suggestion->afterStepKey !== null) {
            $data['after_step'] = $suggestion->afterStepKey;
        }
        if ($suggestion->observedNextSteps !== []) {
            $data['observed_next_steps'] = $suggestion->observedNextSteps;
        }
        $data['message'] = $suggestion->message;
        if ($suggestion->documentUuids !== []) {
            $data['document_uuids'] = $suggestion->documentUuids;
        }

        return $data;
    }
}
