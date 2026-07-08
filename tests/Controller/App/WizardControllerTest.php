<?php

namespace App\Tests\Controller\App;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class WizardControllerTest extends AppWebTestCase
{
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
        self::assertStringContainsString('Completion', $html);
        self::assertStringContainsString('unknown', $html);
        self::assertStringContainsString('Route visits are not tracked yet.', $html);
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
