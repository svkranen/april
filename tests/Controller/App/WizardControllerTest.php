<?php

namespace App\Tests\Controller\App;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class WizardControllerTest extends AppWebTestCase
{
    public function testWizardIndexListsAvailableGuidedTours(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/wizards');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Guided tours', $html);
        self::assertStringContainsString('First Insight', $html);
        self::assertStringContainsString('Guide new users through the Incident Management demo.', $html);
        self::assertStringContainsString('developer', $html);
        self::assertStringContainsString('incident-management', $html);
        self::assertSelectorExists('nav.app-nav a[href="/app/wizards"]');
        self::assertSelectorExists('a[href="/app/wizards/first-insight"]');
    }

    public function testFirstInsightWizardPageRendersReadOnlyView(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/wizards/first-insight');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('First Insight', $html);
        self::assertStringContainsString('Welcome to APRIL', $html);
        self::assertStringContainsString('Prerequisites', $html);
        self::assertStringContainsString('status', $html);
        self::assertStringContainsString('ok', $html);
        self::assertStringContainsString('Actions', $html);
        self::assertStringContainsString('Items &amp; Findings', $html);
        self::assertStringContainsString('Progress', $html);
        self::assertStringContainsString('Wizard progress is not persisted yet.', $html);
        self::assertStringContainsString('Completion', $html);
        self::assertStringContainsString('unknown', $html);
        self::assertStringContainsString('Route visits are not tracked yet.', $html);
        self::assertSelectorExists('nav.app-nav a[href="/app/wizards"]');
        self::assertSelectorExists('a[href="/app/templates/incident-management/documents?withFindings=1"]');
        self::assertSelectorExists('a[href="/app/intelligence/documents/10000000-0000-4000-8000-000000000004"]');
    }

    public function testUnknownWizardReturns404(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/wizards/does-not-exist');

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownWizardReportsKeyInExceptionMessage(): void
    {
        $client = self::createAuthenticatedClient();
        $client->catchExceptions(false);

        try {
            $client->request('GET', '/app/wizards/does-not-exist');
            self::fail('Expected NotFoundHttpException.');
        } catch (NotFoundHttpException $exception) {
            self::assertStringContainsString('does-not-exist', $exception->getMessage());
        }
    }
}
