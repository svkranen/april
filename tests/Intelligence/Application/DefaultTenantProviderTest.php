<?php

namespace App\Tests\Intelligence\Application;

use App\Intelligence\Application\CurrentTenantProvider;
use App\Intelligence\Infrastructure\Tenant\DefaultTenantProvider;
use PHPUnit\Framework\TestCase;

final class DefaultTenantProviderTest extends TestCase
{
    public function testDefaultTenantProviderReturnsDefaultTenant(): void
    {
        $provider = new DefaultTenantProvider();

        self::assertInstanceOf(CurrentTenantProvider::class, $provider);
        self::assertSame('default', $provider->getTenantId());
    }
}
