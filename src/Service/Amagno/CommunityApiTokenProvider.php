<?php

namespace App\Service\Amagno;

use RuntimeException;

final class CommunityApiTokenProvider implements ApiTokenProviderInterface
{
    public function tokenForCredential(int $credentialId): string
    {
        throw new RuntimeException(sprintf(
            'Credential-basierter Amagno Tokenabruf ist im Community-Default nicht verfuegbar. Setze AMAGNO_API_TOKEN oder aktiviere den Enterprise Amagno-Connector fuer Credential-ID %d.',
            $credentialId
        ));
    }
}
