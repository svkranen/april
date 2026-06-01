<?php

namespace App\Intelligence\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'intelligence_process_version')]
#[ORM\UniqueConstraint(name: 'uniq_intelligence_process_version_process_version', columns: ['process_key', 'version'])]
#[ORM\Index(columns: ['process_key', 'valid_from'], name: 'idx_intelligence_process_version_process_valid_from')]
class ProcessVersionEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'process_key', type: 'string', length: 255)]
    private string $processKey;

    #[ORM\Column(type: 'string', length: 128)]
    private string $version;

    #[ORM\Column(name: 'valid_from', type: 'datetime_immutable')]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    public function getId(): ?int { return $this->id; }
    public function setProcessKey(string $processKey): void { $this->processKey = $processKey; }
    public function getProcessKey(): string { return $this->processKey; }
    public function setVersion(string $version): void { $this->version = $version; }
    public function getVersion(): string { return $this->version; }
    public function setValidFrom(DateTimeImmutable $validFrom): void { $this->validFrom = $validFrom; }
    public function getValidFrom(): DateTimeImmutable { return $this->validFrom; }
    public function setDescription(?string $description): void { $this->description = $description; }
    public function getDescription(): ?string { return $this->description; }
    public function setCreatedAt(DateTimeImmutable $createdAt): void { $this->createdAt = $createdAt; }
    public function getCreatedAt(): DateTimeImmutable { return $this->createdAt; }
}
