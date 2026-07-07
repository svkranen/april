<?php

namespace App\Service\Amagno;

use Iileven\AmagnoConnector\Interface\TokenProviderInterface;

final class AmagnoConnectorTokenProvider implements ApiTokenProviderInterface
{
    public function __construct(
        private readonly TokenProviderInterface $connectorTokenProvider
    ) {
    }

    public function tokenForCredential(int $credentialId): string
    {
        return $this->connectorTokenProvider
            ->getToken($credentialId)
            ->getTokenString();
    }
}
