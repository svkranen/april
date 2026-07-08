<?php

namespace App\Tests\Wizard;

use App\Wizard\WizardDefinitionLoader;
use App\Wizard\WizardSummaryProvider;
use PHPUnit\Framework\TestCase;

final class WizardSummaryProviderTest extends TestCase
{
    public function testProvidesSummaryForFirstInsightWizard(): void
    {
        $summaries = (new WizardSummaryProvider(
            new WizardDefinitionLoader(dirname(__DIR__, 2).'/config/april/wizards')
        ))->all();

        self::assertNotEmpty($summaries);

        $summary = $summaries[0];
        self::assertSame('first-insight', $summary->key);
        self::assertSame('First Insight', $summary->title);
        self::assertSame('Guide new users through the Incident Management demo.', $summary->description);
        self::assertSame(['developer', 'architect', 'process_owner'], $summary->audience);
        self::assertSame([
            'key' => 'incident-management',
            'template' => 'incident-management',
        ], $summary->scenario);
    }
}
