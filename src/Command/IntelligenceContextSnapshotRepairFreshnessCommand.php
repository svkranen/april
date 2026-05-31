<?php

namespace App\Command;

use App\Intelligence\Infrastructure\Doctrine\Entity\ContextSnapshotEntity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'intelligence:context-snapshot:repair-freshness',
    description: 'Recalculates ContextSnapshot freshness from occurred_at and loaded_at.'
)]
final class IntelligenceContextSnapshotRepairFreshnessCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-delay-seconds', null, InputOption::VALUE_REQUIRED, 'Freshness window for is_fresh_for_decision_check', '300')
            ->addOption('process-key', null, InputOption::VALUE_REQUIRED, 'Restrict repair to one process key')
            ->addOption('document-uuid', null, InputOption::VALUE_REQUIRED, 'Restrict repair to one document UUID')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count repairs without writing changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maxDelaySeconds = filter_var($input->getOption('max-delay-seconds'), FILTER_VALIDATE_INT);
        if ($maxDelaySeconds === false || $maxDelaySeconds < 0) {
            $output->writeln('<error>--max-delay-seconds must be a non-negative integer.</error>');

            return Command::INVALID;
        }

        $processKey = $input->getOption('process-key');
        $documentUuid = $input->getOption('document-uuid');
        $dryRun = $input->getOption('dry-run') === true;

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('snapshot')
            ->from(ContextSnapshotEntity::class, 'snapshot')
            ->where('snapshot.occurredAt IS NOT NULL')
            ->andWhere('snapshot.loadedAt IS NOT NULL')
            ->orderBy('snapshot.id', 'ASC');

        if ($processKey !== null) {
            $queryBuilder->andWhere('snapshot.processKey = :processKey');
            $queryBuilder->setParameter('processKey', (string) $processKey);
        }

        if ($documentUuid !== null) {
            $queryBuilder->andWhere('snapshot.documentUuid = :documentUuid');
            $queryBuilder->setParameter('documentUuid', (string) $documentUuid);
        }

        $checked = 0;
        $changed = 0;
        foreach ($queryBuilder->getQuery()->toIterable() as $snapshot) {
            if (!$snapshot instanceof ContextSnapshotEntity) {
                continue;
            }

            ++$checked;
            $oldFreshnessSeconds = $snapshot->getFreshnessSeconds();
            $oldIsFreshForDecisionCheck = $snapshot->isFreshForDecisionCheck();
            $newFreshnessSeconds = $snapshot->calculatedFreshnessSeconds();
            $newIsFreshForDecisionCheck = $snapshot->calculatedIsFreshForDecisionCheck($maxDelaySeconds);

            if ($oldFreshnessSeconds === $newFreshnessSeconds && $oldIsFreshForDecisionCheck === $newIsFreshForDecisionCheck) {
                continue;
            }

            ++$changed;
            if (!$dryRun) {
                $snapshot->setFreshnessSeconds($newFreshnessSeconds);
                $snapshot->setIsFreshForDecisionCheck($newIsFreshForDecisionCheck);
            }
        }

        if (!$dryRun && $changed > 0) {
            $this->entityManager->flush();
        }

        $output->writeln(sprintf('checked: %d', $checked));
        $output->writeln(sprintf('%s: %d', $dryRun ? 'would_repair' : 'repaired', $changed));

        return Command::SUCCESS;
    }
}
