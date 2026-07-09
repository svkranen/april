<?php

namespace App\Tests\Service\Settings;

use App\Service\Settings\TemplatePlaceholderParser;
use PHPUnit\Framework\TestCase;

class TemplatePlaceholderParserTest extends TestCase
{
    public function testParsesNormalFieldMarkers(): void
    {
        $parser = new TemplatePlaceholderParser();

        self::assertSame([
            '[:Kontoart:]',
            '[:Belegdatum:]',
            '[:MWSt-Satz:]',
        ], $parser->parse('A [:Kontoart:] B [:Belegdatum:] C [:MWSt-Satz:]'));
    }

    public function testParsesStampMarkers(): void
    {
        $parser = new TemplatePlaceholderParser();

        self::assertSame([
            '[::Stempel::]',
            '[::DebKredNr::]',
        ], $parser->parse('[::Stempel::] [:ignored:inside?] [::DebKredNr::]'));
    }

    public function testDeduplicatesWhileKeepingFirstTemplateOrder(): void
    {
        $parser = new TemplatePlaceholderParser();

        self::assertSame([
            '[:A:]',
            '[::Stempel::]',
            '[:B:]',
        ], $parser->parse('[:A:][::Stempel::][:A:][:B:][::Stempel::]'));
    }

    public function testExcludesRepeatAndSplitControlMarkers(): void
    {
        $parser = new TemplatePlaceholderParser();

        self::assertSame([
            '[:A:]',
            '[:B:]',
        ], $parser->parse('[:repeatstart:][:A:][:repeatend:][:splitstart:][:B:][:splitend:]'));
    }

    public function testParsesOnPremTemplate(): void
    {
        $parser = new TemplatePlaceholderParser();
        $template = '[:repeatstart:][:Kontoart:][:Kostenstelle:][::DebKredNr::][::Belegnr_Eingangsrechnung::][:repeatend:]';
        $placeholders = $parser->parse($template);

        self::assertNotContains('[:repeatstart:]', $placeholders);
        self::assertNotContains('[:repeatend:]', $placeholders);
        self::assertNotContains('[:splitstart:]', $placeholders);
        self::assertNotContains('[:splitend:]', $placeholders);
        self::assertSame('[:Kontoart:]', $placeholders[0]);
        self::assertContains('[:Kostenstelle:]', $placeholders);
        self::assertContains('[::DebKredNr::]', $placeholders);
        self::assertContains('[::Belegnr_Eingangsrechnung::]', $placeholders);
        self::assertSame(count($placeholders), count(array_unique($placeholders)));
    }

    public function testParsesDebitorenTemplateWhenPresent(): void
    {
        $parser = new TemplatePlaceholderParser();
        $placeholders = $parser->parse('[:splitstart:][:Kontoart:][::Belegnr_Aufmass::][::Belegnr_Gutschriftsanzeige::][::Belegnr_Ausgangsrechnung::][:splitend:]');

        self::assertNotContains('[:repeatstart:]', $placeholders);
        self::assertNotContains('[:repeatend:]', $placeholders);
        self::assertNotContains('[:splitstart:]', $placeholders);
        self::assertNotContains('[:splitend:]', $placeholders);
        self::assertSame('[:Kontoart:]', $placeholders[0]);
        self::assertContains('[::Belegnr_Aufmass::]', $placeholders);
        self::assertContains('[::Belegnr_Gutschriftsanzeige::]', $placeholders);
        self::assertContains('[::Belegnr_Ausgangsrechnung::]', $placeholders);
        self::assertSame(count($placeholders), count(array_unique($placeholders)));
    }
}
