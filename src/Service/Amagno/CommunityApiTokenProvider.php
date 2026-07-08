<?php

namespace App\Service\Amagno;

use RuntimeException;

final class CommunityApiTokenProvider implements ApiTokenProviderInterface
{
    public function tokenForCredential(int $credentialId): string
    {
        throw new RuntimeException(sprintf(
            'Credential-basierter Connector-Tokenabruf ist im Community-Default nicht verfuegbar. Konfiguriere einen statischen Token oder aktiviere einen optionalen Connector fuer Credential-ID %d.',
            $credentialId
        ));
    }
}
