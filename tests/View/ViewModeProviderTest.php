<?php

namespace App\Tests\View;

use App\View\ViewModeProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class ViewModeProviderTest extends TestCase
{
    public function testDefaultsToBusinessWithoutQueryOrCookie(): void
    {
        self::assertSame('business', $this->provider(Request::create('/x'))->getMode());
        self::assertFalse($this->provider(Request::create('/x'))->isExpert());
    }

    public function testQueryExpertWins(): void
    {
        $provider = $this->provider(Request::create('/x?view=expert'));
        self::assertSame('expert', $provider->getMode());
        self::assertTrue($provider->isExpert());
    }

    public function testQueryBusinessWins(): void
    {
        self::assertSame('business', $this->provider(Request::create('/x?view=business'))->getMode());
    }

    public function testInvalidQueryFallsBackToBusiness(): void
    {
        self::assertSame('business', $this->provider(Request::create('/x?view=garbage'))->getMode());
    }

    public function testCookieIsUsedWhenNoQuery(): void
    {
        $request = Request::create('/x', 'GET', [], ['april_view_mode' => 'expert']);
        self::assertSame('expert', $this->provider($request)->getMode());
    }

    public function testQueryOverridesCookie(): void
    {
        $request = Request::create('/x?view=business', 'GET', [], ['april_view_mode' => 'expert']);
        self::assertSame('business', $this->provider($request)->getMode());

        $request = Request::create('/x?view=expert', 'GET', [], ['april_view_mode' => 'business']);
        self::assertSame('expert', $this->provider($request)->getMode());
    }

    public function testNormalizeMode(): void
    {
        self::assertSame('expert', ViewModeProvider::normalizeMode('expert'));
        self::assertSame('business', ViewModeProvider::normalizeMode('business'));
        self::assertSame('business', ViewModeProvider::normalizeMode('EXPERT'));
        self::assertSame('business', ViewModeProvider::normalizeMode(null));
        self::assertSame('business', ViewModeProvider::normalizeMode('nonsense'));
    }

    public function testNoRequestDefaultsToBusiness(): void
    {
        self::assertSame('business', (new ViewModeProvider(new RequestStack()))->getMode());
    }

    private function provider(Request $request): ViewModeProvider
    {
        $stack = new RequestStack();
        $stack->push($request);

        return new ViewModeProvider($stack);
    }
}
