<?php

namespace App\Command;

use App\Dto\SyncOptions;
use App\Service\FibuExportService;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'amagno:sync',
    description: 'Polls documents from an Amagno magnet and prepares them for export.'
)]
class AmagnoSyncCommand extends Command
{
    public function __construct(
        private readonly FibuExportService $fibuExportService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('magnet', null, InputOption::VALUE_REQUIRED, 'Magnet ID to poll')
            ->addOption('export', null, InputOption::VALUE_REQUIRED, 'Export target (local, ftp, amagno, sql)')
            ->addOption('matching-profile', null, InputOption::VALUE_OPTIONAL, 'Matching profile to use')
            ->addOption('template', null, InputOption::VALUE_OPTIONAL, 'Template override file name')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Number of documents per poll', 50)
            ->addOption('system', null, InputOption::VALUE_OPTIONAL, 'System identifier', 'onprem')
            ->addOption('token', null, InputOption::VALUE_OPTIONAL, 'Amagno API token')
            ->addOption('vault', null, InputOption::VALUE_OPTIONAL, 'Vault ID for Amagno export')
            ->addOption('folder', null, InputOption::VALUE_OPTIONAL, 'Local export folder')
            ->addOption('ftp-server', null, InputOption::VALUE_OPTIONAL, 'FTP server')
            ->addOption('ftp-user', null, InputOption::VALUE_OPTIONAL, 'FTP user')
            ->addOption('ftp-password', null, InputOption::VALUE_OPTIONAL, 'FTP password')
            ->addOption('ftp-folder', null, InputOption::VALUE_OPTIONAL, 'FTP folder')
            ->addOption('db-host', null, InputOption::VALUE_OPTIONAL, 'Database host for SQL export')
            ->addOption('db-name', null, InputOption::VALUE_OPTIONAL, 'Database name for SQL export')
            ->addOption('db-user', null, InputOption::VALUE_OPTIONAL, 'Database user for SQL export')
            ->addOption('db-password', null, InputOption::VALUE_OPTIONAL, 'Database password for SQL export')
            ->addOption('stamp-id', null, InputOption::VALUE_OPTIONAL, 'Stamp ID to apply after export')
            ->addOption('checkpoint-key', null, InputOption::VALUE_OPTIONAL, 'Custom checkpoint key (defaults to magnet ID)')
            ->addOption('use-checkpoint', null, InputOption::VALUE_NONE, 'Apply checkpoint timestamp when polling')
            ->addOption('since', null, InputOption::VALUE_OPTIONAL, 'ISO date to start polling from (overrides checkpoint)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Fetch and display documents without exporting');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $magnetId = (string) $input->getOption('magnet');
        if ($magnetId === '') {
            throw new RuntimeException('Option --magnet ist erforderlich.');
        }

        $exportTarget = (string) $input->getOption('export');
        if ($exportTarget === '') {
            throw new RuntimeException('Option --export ist erforderlich.');
        }

        $profile = $input->getOption('matching-profile') ?: null;
        $limit = (int) $input->getOption('limit');
        $useCheckpoint = (bool) $input->getOption('use-checkpoint');
        $checkpointKey = $input->getOption('checkpoint-key') ?: $magnetId;

        $since = $this->resolveSinceDate(
            $input->getOption('since')
        );

        $options = new SyncOptions(
            magnetId: $magnetId,
            exportTarget: $exportTarget,
            profile: $profile,
            template: $input->getOption('template') ?: null,
            system: $input->getOption('system') ?? 'onprem',
            token: $input->getOption('token') ?: null,
            vaultId: $input->getOption('vault') ?: null,
            localFolder: $input->getOption('folder') ?: null,
            ftpServer: $input->getOption('ftp-server') ?: null,
            ftpUser: $input->getOption('ftp-user') ?: null,
            ftpPassword: $input->getOption('ftp-password') ?: null,
            ftpFolder: $input->getOption('ftp-folder') ?: null,
            dbHost: $input->getOption('db-host') ?: null,
            dbName: $input->getOption('db-name') ?: null,
            dbUser: $input->getOption('db-user') ?: null,
            dbPassword: $input->getOption('db-password') ?: null,
            stampId: $input->getOption('stamp-id') ?: null,
            dryRun: (bool) $input->getOption('dry-run'),
            useCheckpoint: $useCheckpoint,
            checkpointName: $checkpointKey,
            batchSize: $limit,
            modifiedSince: $since
        );

        $result = $this->fibuExportService->sync($options);
        $documentCount = $result['document_count'] ?? 0;
        $renderedCount = $result['rendered_blocks'] ?? 0;

        if ($documentCount === 0) {
            $output->writeln('<info>Keine neuen Dokumente gefunden.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>%d Dokument(e) verarbeitet, %d Ausgabeblöcke erzeugt.</info>',
            $documentCount,
            $renderedCount
        ));

        if (!empty($result['debug'])) {
            foreach ($result['debug'] as $line) {
                $output->writeln('<comment>'.$line.'</comment>');
            }
        }

        if (!empty($result['checkpoint_from'])) {
            $output->writeln(sprintf('<info>Checkpoint verwendet: %s</info>', $result['checkpoint_from']));
        }
        if (!empty($result['checkpoint_updated'])) {
            $output->writeln(sprintf('<info>Checkpoint gespeichert: %s</info>', $result['checkpoint_updated']));
        }

        if (!empty($result['documents']) && $options->dryRun) {
            $this->renderPreview($result['documents'], $output);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<int, array<string, mixed>> $documents
     */
    private function renderPreview(array $documents, OutputInterface $output): void
    {
        $output->writeln('<comment>Dokumenten-Vorschau:</comment>');
        foreach (array_slice($documents, 0, 5) as $document) {
            $docId = is_array($document) ? ($document['id'] ?? 'n/a') : ($document->id ?? 'n/a');
            $docNumber = is_array($document) ? ($document['documentNumber'] ?? 'n/a') : ($document->documentNumber ?? 'n/a');
            $change = is_array($document) ? ($document['changeDate'] ?? 'n/a') : ($document->changeDate ?? 'n/a');
            $output->writeln(sprintf(
                '- %s | Nummer: %s | Change: %s',
                $docId,
                $docNumber,
                $change
            ));
        }
    }

    private function resolveSinceDate(?string $sinceOption): ?DateTimeImmutable
    {
        if ($sinceOption) {
            return new DateTimeImmutable($sinceOption);
        }

        return null;
    }
}
