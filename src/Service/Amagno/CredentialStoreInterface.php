<?php

namespace App\Service\Amagno;

interface CredentialStoreInterface
{
    public function getDefaultCredentialId(): ?int;

    public function setCredentials(string $baseUri, string $username, string $password, ?int $credentialId = null): void;

    /**
     * @return array{host: string, user: string, password: string}|null
     */
    public function getCredentials(int $credentialId): ?array;
}
