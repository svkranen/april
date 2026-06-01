<?php

namespace App\Intelligence\Infrastructure\Doctrine\Repository;

use App\Intelligence\Application\ProcessVersionRepository;
use App\Intelligence\Domain\ProcessVersion;
use App\Intelligence\Infrastructure\Doctrine\Entity\ProcessVersionEntity;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineProcessVersionRepository implements ProcessVersionRepository
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function findByProcessKey(string $processKey): array
    {
        /** @var array<int, ProcessVersionEntity> $entities */
        $entities = $this->entityManager->getRepository(ProcessVersionEntity::class)->findBy(
            ['processKey' => $processKey],
            ['validFrom' => 'ASC', 'id' => 'ASC']
        );

        return array_map(fn (ProcessVersionEntity $entity): ProcessVersion => $this->toDomain($entity), $entities);
    }

    public function findOneByProcessKeyAndVersion(string $processKey, string $version): ?ProcessVersion
    {
        $entity = $this->entityManager->getRepository(ProcessVersionEntity::class)->findOneBy([
            'processKey' => $processKey,
            'version' => $version,
        ]);

        return $entity instanceof ProcessVersionEntity ? $this->toDomain($entity) : null;
    }

    public function latestForProcess(string $processKey): ?ProcessVersion
    {
        $entities = $this->entityManager->getRepository(ProcessVersionEntity::class)->findBy(
            ['processKey' => $processKey],
            ['validFrom' => 'DESC', 'id' => 'DESC'],
            1
        );
        $entity = $entities[0] ?? null;

        return $entity instanceof ProcessVersionEntity ? $this->toDomain($entity) : null;
    }

    public function save(ProcessVersion $processVersion): ProcessVersion
    {
        $entity = $processVersion->id === null ? new ProcessVersionEntity() : $this->entityManager->find(ProcessVersionEntity::class, $processVersion->id);
        if (!$entity instanceof ProcessVersionEntity) {
            $entity = new ProcessVersionEntity();
        }

        $entity->setProcessKey($processVersion->processKey);
        $entity->setVersion($processVersion->version);
        $entity->setValidFrom($processVersion->validFrom);
        $entity->setDescription($processVersion->description);
        $entity->setCreatedAt($processVersion->createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $this->toDomain($entity);
    }

    private function toDomain(ProcessVersionEntity $entity): ProcessVersion
    {
        return new ProcessVersion(
            $entity->getId(),
            $entity->getProcessKey(),
            $entity->getVersion(),
            $entity->getValidFrom(),
            $entity->getDescription(),
            $entity->getCreatedAt()
        );
    }
}
