<?php

namespace App\Tests\Intelligence\Domain;

use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateFieldMapping;
use App\Intelligence\Domain\ProcessTemplateMatch;
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
                requiredStepKeys: ['eingang', 'pruefung']
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
                'required_steps' => ['eingang', 'pruefung'],
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

    public function testSerializesRepeatedEventAnalysisHints(): void
    {
        $result = new ProcessTemplateSuggestionResult(
            new ProcessTemplate('eingangsrechnung'),
            suggestions: [
                new ProcessTemplateSuggestionNote(
                    'possible_multi_approval',
                    'Möglicher dynamischer Mehrpersonenfreigabe-Prozess. Bitte prüfen, ob hierfür ein contextbasierter signCheck definiert werden soll.',
                    documentUuids: ['doc-a', 'doc-b'],
                    eventKey: 'B',
                    affectedDocuments: 2,
                    minRepetitions: 2,
                    maxRepetitions: 3,
                    avgRepetitions: 2.5,
                    previousEvents: [['event_key' => 'A', 'count' => 2]],
                    followingEvents: [['event_key' => 'C', 'count' => 2]]
                ),
            ]
        );

        $data = (new ProcessTemplateSuggestionArraySerializer())->toArray($result);

        self::assertSame($data['suggestions'], $data['analysis_hints']);
        self::assertSame('possible_multi_approval', $data['analysis_hints'][0]['type']);
        self::assertSame('B', $data['analysis_hints'][0]['event_key']);
        self::assertTrue($data['analysis_hints'][0]['repeatable']);
        self::assertSame(2, $data['analysis_hints'][0]['affected_documents']);
        self::assertSame(2, $data['analysis_hints'][0]['min_repetitions']);
        self::assertSame(3, $data['analysis_hints'][0]['max_repetitions']);
        self::assertSame(2.5, $data['analysis_hints'][0]['avg_repetitions']);
        self::assertSame([['event_key' => 'A', 'count' => 2]], $data['analysis_hints'][0]['previous_events']);
        self::assertSame([['event_key' => 'C', 'count' => 2]], $data['analysis_hints'][0]['following_events']);
    }

    public function testSerializesJourneySuggestionFields(): void
    {
        $result = new ProcessTemplateSuggestionResult(
            new ProcessTemplate(
                'document_journey',
                scope: 'journey',
                match: new ProcessTemplateMatch(['generic_document_import']),
                steps: [
                    new ProcessTemplateStep(
                        'generic_document_import',
                        type: 'process',
                        processKey: 'generic_document_import',
                        required: true
                    ),
                    new ProcessTemplateStep(
                        'optional_export',
                        type: 'process',
                        processKey: 'export_nevaris',
                        required: false
                    ),
                ],
                transitions: [
                    new ProcessTemplateTransition('generic_document_import', 'optional_export'),
                ]
            )
        );

        self::assertSame(
            [
                'key' => 'document_journey',
                'scope' => 'journey',
                'match' => [
                    'any_process' => ['generic_document_import'],
                ],
                'version' => 'draft',
                'steps' => [
                    [
                        'key' => 'generic_document_import',
                        'type' => 'process',
                        'process_key' => 'generic_document_import',
                        'required' => true,
                    ],
                    [
                        'key' => 'optional_export',
                        'type' => 'process',
                        'process_key' => 'export_nevaris',
                        'required' => false,
                    ],
                ],
                'transitions' => [
                    ['from' => 'generic_document_import', 'to' => 'optional_export'],
                ],
                'context_profile' => [
                    'required' => [],
                ],
            ],
            (new ProcessTemplateSuggestionArraySerializer())->toArray($result)
        );
    }
}
