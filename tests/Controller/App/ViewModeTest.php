<?php

namespace App\Tests\Controller\App;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class ViewModeTest extends AppWebTestCase
{
    private const EXPERT_MARKER = 'Check-Keys (technisch)';

    public function testDefaultIsBusiness(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::EXPERT_MARKER, (string) $client->getResponse()->getContent());
    }

    public function testLayoutContainsToggleLinks(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates');

        self::assertSelectorExists('.view-toggle');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Fachsicht', $html);
        self::assertStringContainsString('Technische Sicht', $html);
    }

    public function testViewExpertSetsCookie(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates?view=expert');

        self::assertSame('expert', $this->responseCookie($client, 'april_view_mode'));
    }

    public function testViewBusinessSetsCookie(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates?view=business');

        self::assertSame('business', $this->responseCookie($client, 'april_view_mode'));
    }

    public function testExpertQueryShowsExpertContent(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen?view=expert');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::EXPERT_MARKER, (string) $client->getResponse()->getContent());
    }

    public function testCookieDrivesExpertOnFollowupRequest(): void
    {
        $client = self::createAuthenticatedClient();
        // First request sets the cookie via query parameter.
        $client->request('GET', '/app/templates?view=expert');
        self::assertSame('expert', $this->responseCookie($client, 'april_view_mode'));

        // Follow-up without query parameter relies on the stored cookie.
        $client->request('GET', '/app/templates/ai-rechnungen');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::EXPERT_MARKER, (string) $client->getResponse()->getContent());
    }

    public function testInvalidViewFallsBackToBusiness(): void
    {
        $client = self::createAuthenticatedClient();
        $client->request('GET', '/app/templates/ai-rechnungen?view=garbage');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString(self::EXPERT_MARKER, (string) $client->getResponse()->getContent());
        self::assertSame('business', $this->responseCookie($client, 'april_view_mode'));
    }

    private function responseCookie(KernelBrowser $client, string $name): ?string
    {
        foreach ($client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie->getValue();
            }
        }

        return null;
    }
}
