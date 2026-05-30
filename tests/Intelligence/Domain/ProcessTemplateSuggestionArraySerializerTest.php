<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateFieldMapping;
use App\Intelligence\Domain\ProcessTemplateParallelGroup;
use App\Intelligence\Domain\ProcessTemplateStep;
use App\Intelligence\Domain\ProcessTemplateSuggestionArraySerializer;
use App\Intelligence\Domain\ProcessTemplateSuggestionNote;
use App\Intelligence\Domain\ProcessTemplateSuggestionResult;
use App\Intelligence\Domain\ProcessTemplateSuggestionWarning;
use App\Intelligence\Domain\ProcessTemplateTransition;
use App\Intelligence\Domain\SuggestedTransition;
use PHPUnit\Framework\TestCase;

class ProcessTemplateSuggestionArraySerializerTest extends TestCase
{
    public function testSerializesSingleDocumentSuggestionFormat(): void
    {
        $result = new ProcessTemplateSuggestionResult(
            new ProcessTemplate(
                'eingangsrechnung',
                'draft',
                'Eingangsrechnung',
                steps: [
                    new ProcessTemplateStep('eingang', 'Eingang'),
                    new ProcessTemplateStep('pruefung', 'Pruefung'),
                ],
                transitions: [
                    new ProcessTemplateTransition('eingang', 'pruefung'),
                ],
            )
        );

        self::assertSame(
            [
                'key' => 'eingangsrechnung',
                'name' => 'Eingangsrechnung',
                'version' => 'draft',
                'steps' => [
                    ['key' => 'eingang', 'name' => 'Eingang'],
                    ['key' => 'pruefung', 'name' => 'Pruefung'],
                ],
                'transitions' => [
                    ['from' => 'eingang', 'to' => 'pruefung'],
                ],
                'context_profile' => [
                    'required' => [],
                ],
            ],
            (new ProcessTemplateSuggestionArraySerializer())->toArray($result)
        );
    }

    public function testSerializesMultiDocumentSuggestionMetadata(): void
    {
        $result = new ProcessTemplateSuggestionResult(
            new ProcessTemplate(
                'eingangsrechnung',
                steps: [
                    new ProcessTemplateStep('A'),
                    new ProcessTemplateStep('B'),
                    new ProcessTemplateStep('C'),
                ],
                parallelGroups: [
                    new ProcessTemplateParallelGroup('suggested_parallel_1', 'A', ['B', 'C'], 'any'),
                ],
                fieldMappings: [
                    'invoice_direction' => new ProcessTemplateFieldMapping(
                        'invoice_direction',
                        'amagno',
                        'Eingang/Ausgang'
                    ),
                    'amount_net' => new ProcessTemplateFieldMapping(
                        'amount_net',
                        'amagno',
                        'Nettobetrag',
                        valueType: 'number'
                    ),
                ],
            ),
            ['doc-a', 'doc-b'],
            [
                new SuggestedTransition('A', 'B', 1, 1.0),
                new SuggestedTransition('C', 'B', 1, 0.5),
            ],
            [
                new ProcessTemplateSuggestionWarning(
                    'possible_parallel',
                    'Possible parallel steps detected: B, C. Documents: doc-a, doc-b.',
                    ['doc-a', 'doc-b']
                ),
            ],
            [
                new ProcessTemplateSuggestionNote(
                    'possible_parallel_group',
                    'Observed both orders across document timelines.',
                    'suggested_parallel_1',
                    ['doc-a', 'doc-b'],
                    0.5
                ),
                new ProcessTemplateSuggestionNote(
                    'possible_decision_point',
                    'Multiple next steps observed after A. Context fields may be required to explain routing.',
                    afterStepKey: 'A',
                    observedNextSteps: ['B', 'C']
                ),
            ]
        );

        self::assertSame(
            [
                'key' => 'eingangsrechnung',
                'version' => 'draft',
                'steps' => [
                    ['key' => 'A'],
                    ['key' => 'B'],
                    ['key' => 'C'],
                ],
                'transitions' => [
                    ['from' => 'A', 'to' => 'B', 'observed_count' => 1, 'confidence' => 1.0],
                    ['from' => 'C', 'to' => 'B', 'observed_count' => 1, 'confidence' => 0.5],
                ],
                'context_profile' => [
                    'required' => [],
                ],
                'documents_used' => 2,
                'document_uuids' => ['doc-a', 'doc-b'],
                'warnings' => [
                    [
                        'type' => 'possible_parallel',
                        'message' => 'Possible parallel steps detected: B, C. Documents: doc-a, doc-b.',
                        'document_uuids' => ['doc-a', 'doc-b'],
                    ],
                ],
                'field_mapping' => [
                    'invoice_direction' => [
                        'source' => 'amagno',
                        'tag_name' => 'Eingang/Ausgang',
                    ],
                    'amount_net' => [
                        'source' => 'amagno',
                        'tag_name' => 'Nettobetrag',
                        'value_type' => 'number',
                    ],
                ],
                'parallel_groups' => [
                    [
                        'key' => 'suggested_parallel_1',
                        'after' => 'A',
                        'required_steps' => ['B', 'C'],
                        'order' => 'any',
                        'confidence' => 0.5,
                        'reason' => 'Observed both orders across document timelines.',
                        'document_uuids' => ['doc-a', 'doc-b'],
                    ],
                ],
                'suggestions' => [
                    [
                        'type' => 'possible_parallel_group',
                        'parallel_group_key' => 'suggested_parallel_1',
                        'message' => 'Observed both orders across document timelines.',
                        'document_uuids' => ['doc-a', 'doc-b'],
                    ],
                    [
                        'type' => 'possible_decision_point',
                        'after_step' => 'A',
                        'observed_next_steps' => ['B', 'C'],
                        'message' => 'Multiple next steps observed after A. Context fields may be required to explain routing.',
                    ],
                ],
            ],
            (new ProcessTemplateSuggestionArraySerializer())->toArray($result)
        );
    }
}
