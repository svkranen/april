<?php

namespace App\Tests\SignatureCheck;

use App\SignatureCheck\AmagnoTagValueExtractor;
use PHPUnit\Framework\TestCase;

class AmagnoTagValueExtractorTest extends TestCase
{
    public function testItExtractsSingleLineAndSelectionValues(): void
    {
        $extractor = new AmagnoTagValueExtractor();

        $payload = [
            'singleLineStrings' => [
                [
                    'tagDefinitionId' => 'required',
                    'value' => 'Anna',
                ],
                [
                    'tagDefinitionId' => 'required',
                    'value' => 'Bernd',
                ],
            ],
            'selections' => [
                [
                    'tagDefinitionId' => 'confirmed',
                    'selectedNodeIds' => ['node-1', 'node-2'],
                ],
            ],
        ];

        $resolver = static fn (string $nodeId): array => match ($nodeId) {
            'node-1' => ['value' => 'Anna'],
            'node-2' => ['value' => 'Bernd'],
            default => [],
        };

        $this->assertSame(['Anna', 'Bernd'], $extractor->extractValues($payload, 'required', $resolver));
        $this->assertSame(['Anna', 'Bernd'], $extractor->extractValues($payload, 'confirmed', $resolver));
    }
}
