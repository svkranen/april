<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\TemplateYamlDiffPreview;
use PHPUnit\Framework\TestCase;

final class TemplateYamlDiffPreviewTest extends TestCase
{
    public function testBuildsJourneyMatchAnyProcessPreview(): void
    {
        $preview = TemplateYamlDiffPreview::forJourneyMatchAnyProcess([
            'RM_TEST_aufmass',
            'RM_TEST_aufmass',
            'RM_TEST_NevarisExport',
        ]);

        self::assertNotNull($preview);
        self::assertSame(
            [
                ['kind' => TemplateYamlDiffPreview::KIND_CONTEXT, 'text' => 'match:'],
                ['kind' => TemplateYamlDiffPreview::KIND_CONTEXT, 'text' => '  any_process:'],
                ['kind' => TemplateYamlDiffPreview::KIND_ADDITION, 'text' => '    - "RM_TEST_aufmass"'],
                ['kind' => TemplateYamlDiffPreview::KIND_ADDITION, 'text' => '    - "RM_TEST_NevarisExport"'],
            ],
            $preview->lines
        );
    }
}
