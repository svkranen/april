<?php

namespace App\Service\Processing;

class DocumentMatrixBuilder
{
    /**
     * @param array<int, array<string, mixed>> $documents
     * @param callable $tagFetcher returns the tag payload for a document id
     * @param callable $selectionFetcher returns the selection node info for a node id
     * @return array<int, array<string, array<int, object>>>
     */
    public function build(array $documents, callable $tagFetcher, callable $selectionFetcher, array $matching): array
    {
        $matrix = [];

        foreach ($documents as $documentData) {
            $document = (object) $documentData;
            $tagPayload = $tagFetcher($document->id);
            $taglist = json_decode(json_encode($tagPayload), false);

            if (!isset($taglist->numbers) || !is_array($taglist->numbers)) {
                $taglist->numbers = [];
            }

            $docnumbertag = (object) [
                'id' => '4b31e4d5-28cd-4c77-8b30-1b3a5861415e',
                'tagDefinitionId' => '4b31e4d5-28cd-4c77-8b30-1b3a5861415e',
                'value' => intval($document->documentNumber) / 10000,
                'type' => 'numbers',
            ];
            $taglist->numbers[] = $docnumbertag;

            $matrix[$document->id] = [];

            foreach ($taglist as $groupType => $group) {
                if (!is_array($group)) {
                    continue;
                }
                foreach ($group as $tagEntry) {
                    $tag = json_decode(json_encode($tagEntry), false);
                    if (!isset($tag->tagDefinitionId)) {
                        continue;
                    }
                    if (!in_array($tag->tagDefinitionId, $matching, true)) {
                        continue;
                    }

                    if ($groupType === 'selections') {
                        if (!isset($tag->selectedNodeIds) || count($tag->selectedNodeIds) <= 0) {
                            continue;
                        }
                        $node = $tag->selectedNodeIds[0];
                        $selectedNode = $selectionFetcher($node);
                        $selectedNode = json_decode(json_encode($selectedNode), false);
                        $selectedNode->type = $groupType;
                        $selectedNode->tagDefinitionId = $tag->tagDefinitionId;
                        $selectedNode->tagGroupDefinitionId = $tag->tagGroupDefinitionId ?? null;
                        $selectedNode->tagGroupId = $tag->tagGroupId ?? null;
                        $matrix[$document->id][$tag->tagDefinitionId][] = $selectedNode;
                    } else {
                        $tag->type = $groupType;
                        $matrix[$document->id][$tag->tagDefinitionId][] = $tag;
                    }
                }
            }

            $doctype = (object) [
                'id' => 'doctype_id',
                'tagDefinitionId' => 'doctype_tagdefinitionid',
                'value' => $document->documentTypeId,
            ];
            $matrix[$document->id]['doctype'] = [$doctype];
        }

        return array_values($matrix);
    }
}
