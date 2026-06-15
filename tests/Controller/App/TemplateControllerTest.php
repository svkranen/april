<?php

namespace App\Tests\Controller\App;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class TemplateControllerTest extends WebTestCase
{
    public function testTemplatesIndexReturns200AndRendersBaseLayout(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/templates');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        // Base layout (templates/web/layout/base.html.twig) was rendered.
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('lang="de"', $html);
        // Navigation contains "Templates".
        self::assertSelectorTextContains('nav.app-nav', 'Templates');
        // Real process template under templates/ is discovered by the catalog.
        self::assertStringContainsString('ai-rechnungen', $html);
    }

    public function testHomeRedirectsToTemplates(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app');

        self::assertResponseRedirects('/app/templates');
    }

    public function testUnknownTemplateSubrouteReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/templates/this-does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTemplateDetailReturns200AndShowsTemplate(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('<!DOCTYPE html>', $html);
        // Template identity + version.
        self::assertStringContainsString('ai-rechnungen', $html);
        self::assertStringContainsString('1.1', $html);
        // Required steps section + a known step from the template.
        self::assertStringContainsString('Required Steps', $html);
        self::assertStringContainsString('01 Rechnungen pruefen', $html);
        // Access short summary (no full coverage matrix in this step).
        self::assertStringContainsString('Kurzfassung', $html);
        self::assertStringContainsString('Access Probes', $html);
    }

    public function testTemplateDetailUnknownKeyReturns404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/templates/does-not-exist-xyz');

        self::assertResponseStatusCodeSame(404);
    }
}
