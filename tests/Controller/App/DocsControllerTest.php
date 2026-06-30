<?php

namespace App\Tests\Controller\App;

class DocsControllerTest extends AppWebTestCase
{
    public function testDocsRequireAuthenticatedUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/docs/index.html');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAuthenticatedUserCanOpenDocs(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/docs/index.html');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=utf-8');
        self::assertStringContainsString('Amagno Intelligence Tool - Entwicklerdoku', (string) $client->getResponse()->getContent());
    }

    public function testLegacyDocsUrlAlsoRequiresAuthenticatedUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/docs/index.html');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testAuthenticatedLegacyDocsUrlRedirectsToInternalDocs(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/docs/index.html');

        self::assertResponseRedirects('/app/docs/index.html');
    }
}
