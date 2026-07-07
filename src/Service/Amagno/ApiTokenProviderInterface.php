<?php

namespace App\Service\Amagno;

interface ApiTokenProviderInterface
{
    public function tokenForCredential(int $credentialId): string;
}
