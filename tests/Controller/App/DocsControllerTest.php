<?php

namespace App\Tests\Controller\App;

class DocsControllerTest extends AppWebTestCase
{
    public function testDocsRequireAuthenticatedUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/docs/doctrine-persistence.md');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAuthenticatedUserCanOpenDocs(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/docs/doctrine-persistence.md');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=utf-8');
        self::assertStringContainsString('Doctrine Persistence', (string) $client->getResponse()->getContent());
    }

    public function testLegacyDocsUrlAlsoRequiresAuthenticatedUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/docs/doctrine-persistence.md');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAuthenticatedLegacyDocsUrlRedirectsToInternalDocs(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/docs/doctrine-persistence.md');

        self::assertResponseRedirects('/app/docs/doctrine-persistence.md');
    }
}
