<?php

namespace App\Intelligence\Application;

interface CurrentTenantProvider
{
    public function getTenantId(): string;
}
