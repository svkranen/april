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

        if ($result->usedDocumentUuids !== []) {
            $data['documents_used'] = count($result->usedDocumentUuids);
            $data['document_uuids'] = $result->usedDocumentUuids;
        }

        if ($result->warnings !== []) {
            $data['warnings'] = array_map(
                static fn (ProcessTemplateSuggestionWarning $warning): array => self::warningToArray($warning),
                $result->warnings
            );
        }

        if ($template->parallelGroups !== []) {
            $data['parallel_groups'] = array_map(
                static fn (ProcessTemplateParallelGroup $parallelGroup): array => self::parallelGroupToArray($parallelGroup),
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
            static fn (ProcessTemplateTransition $transition): array => [
                'from' => $transition->from,
                'to' => $transition->to,
            ],
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
     * @return array<string, mixed>
     */
    private static function parallelGroupToArray(ProcessTemplateParallelGroup $parallelGroup): array
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

        return $data;
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
        $data['message'] = $suggestion->message;
        if ($suggestion->documentUuids !== []) {
            $data['document_uuids'] = $suggestion->documentUuids;
        }

        return $data;
    }
}
