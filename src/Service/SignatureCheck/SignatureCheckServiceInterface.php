<?php

namespace App\Service\SignatureCheck;

use App\Dto\SignatureCheckOptions;

interface SignatureCheckServiceInterface
{
    /**
     * @return array<string, mixed>
     */
    public function check(SignatureCheckOptions $options): array;
}
