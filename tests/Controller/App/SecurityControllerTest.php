<?php

namespace App\Tests\Controller\App;

class SecurityControllerTest extends AppWebTestCase
{
    public function testAppRedirectsAnonymousUserToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/app/templates');

        self::assertResponseRedirects('http://localhost/login');
    }

    public function testLoginAuthenticatesEnvUser(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav.app-nav', 'Logout');
    }

    public function testEventApiDoesNotRequireSessionLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/intelligence/events', [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(400);
        self::assertStringContainsString('unknown_process_key', (string) $client->getResponse()->getContent());
    }
}
