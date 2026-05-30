<?php

namespace App\Tests\Intelligence\Connector\Amagno;

use App\Intelligence\Connector\Amagno\AmagnoFieldMapFactory;
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

        self::assertSame(
            [
                'invoice_direction' => 'Eingang/Ausgang',
                'amount_net' => 'Nettobetrag',
            ],
            (new AmagnoFieldMapFactory())->fromTemplate($template)
        );
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

        self::assertSame(
            ['amount_net' => 'tag-amount-net'],
            (new AmagnoFieldMapFactory())->fromTemplate($template)
        );
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

        self::assertSame(
            ['invoice_direction' => 'Eingang/Ausgang'],
            (new AmagnoFieldMapFactory())->fromTemplate($template)
        );
    }

    public function testEmptyFieldMappingReturnsEmptyMap(): void
    {
        self::assertSame(
            [],
            (new AmagnoFieldMapFactory())->fromTemplate(new ProcessTemplate('invoice'))
        );
    }
}
