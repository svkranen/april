<?php

namespace App\Tests\Controller\App;

class SecurityControllerTest extends AppWebTestCase
{
    public function testLoginRendersForAnonymousUser(): void
    {
        $client = static::createClient();
        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'APRIL Login');
        self::assertSelectorExists('input[name="_csrf_token"]');
    }

    public function testLoginWithLastUsernameStillRendersForAnonymousUser(): void
    {
        $client = static::createClient();
        $session = self::getContainer()->get('session.factory')->createSession();
        $session->set('_security.last_username', 'april-test');
        $session->save();
        $client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie($session->getName(), $session->getId()));

        $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'APRIL Login');
        self::assertInputValueSame('username', 'april-test');
    }

    public function testLoginRedirectsAuthenticatedUserToApp(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/login');

        self::assertResponseRedirects('/app');
    }

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

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('invalid_signature', (string) $client->getResponse()->getContent());
    }
}
