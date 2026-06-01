<?php

namespace App\Intelligence\Infrastructure\Tenant;

use App\Intelligence\Application\CurrentTenantProvider;

final class DefaultTenantProvider implements CurrentTenantProvider
{
    public function getTenantId(): string
    {
        return 'default';
    }
}
