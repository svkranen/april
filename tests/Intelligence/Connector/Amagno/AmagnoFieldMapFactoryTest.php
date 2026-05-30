<?php

namespace App\Tests\Intelligence\Connector\Amagno;

use App\Intelligence\Connector\Amagno\AmagnoFieldMapFactory;
use App\Intelligence\Connector\Amagno\AmagnoFieldMapping;
use App\Intelligence\Domain\ProcessTemplate;
use App\Intelligence\Domain\ProcessTemplateFieldMapping;
use PHPUnit\Framework\TestCase;

class AmagnoFieldMapFactoryTest extends TestCase
{
    public function testBuildsFieldMapFromTagNames(): void
    {
        $template = new ProcessTemplate(
            'invoice',
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
            ]
        );

        $fieldMap = (new AmagnoFieldMapFactory())->fromTemplate($template);

        self::assertContainsOnlyInstancesOf(AmagnoFieldMapping::class, $fieldMap);
        self::assertSame('Eingang/Ausgang', $fieldMap['invoice_direction']->tagName);
        self::assertNull($fieldMap['invoice_direction']->tagId);
        self::assertSame('Nettobetrag', $fieldMap['amount_net']->tagName);
        self::assertSame('number', $fieldMap['amount_net']->valueType);
    }

    public function testTagIdTakesPrecedenceOverTagName(): void
    {
        $template = new ProcessTemplate(
            'invoice',
            fieldMappings: [
                'amount_net' => new ProcessTemplateFieldMapping(
                    'amount_net',
                    'amagno',
                    'Nettobetrag',
                    'tag-amount-net'
                ),
            ]
        );

        $fieldMap = (new AmagnoFieldMapFactory())->fromTemplate($template);

        self::assertSame('tag-amount-net', $fieldMap['amount_net']->tagId);
        self::assertSame('Nettobetrag', $fieldMap['amount_net']->tagName);
    }

    public function testIgnoresNonAmagnoMappings(): void
    {
        $template = new ProcessTemplate(
            'invoice',
            fieldMappings: [
                'amount_net' => new ProcessTemplateFieldMapping(
                    'amount_net',
                    'external',
                    'Nettobetrag'
                ),
                'invoice_direction' => new ProcessTemplateFieldMapping(
                    'invoice_direction',
                    'amagno',
                    'Eingang/Ausgang'
                ),
            ]
        );

        $fieldMap = (new AmagnoFieldMapFactory())->fromTemplate($template);

        self::assertSame(['invoice_direction'], array_keys($fieldMap));
        self::assertSame('Eingang/Ausgang', $fieldMap['invoice_direction']->tagName);
    }

    public function testEmptyFieldMappingReturnsEmptyMap(): void
    {
        self::assertSame(
            [],
            (new AmagnoFieldMapFactory())->fromTemplate(new ProcessTemplate('invoice'))
        );
    }
}
