<?php

namespace App\Command;

use App\Dto\SyncOptions;
use App\Service\Amagno\ConnectionDefinition;
use App\Service\Amagno\ConnectionRegistry;
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
        private readonly FibuExportService $fibuExportService,
        private readonly ConnectionRegistry $connectionRegistry
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
            ->addOption('api-user', null, InputOption::VALUE_OPTIONAL, 'Benutzername des technischen Amagno-Kontos')
            ->addOption('api-password', null, InputOption::VALUE_OPTIONAL, 'Passwort des technischen Amagno-Kontos')
            ->addOption('api-auth-type', null, InputOption::VALUE_OPTIONAL, 'AuthenticationType für den Login (optional)')
            ->addOption('base-uri', null, InputOption::VALUE_OPTIONAL, 'Amagno Base URI (überschreibt AMAGNO_BASE_URI)')
            ->addOption('credential-id', null, InputOption::VALUE_OPTIONAL, 'Credential-ID für den Tokenabruf')
            ->addOption('success-stamp', null, InputOption::VALUE_OPTIONAL, 'Stempel-ID für erfolgreiche Verarbeitung')
            ->addOption('error-stamp', null, InputOption::VALUE_OPTIONAL, 'Stempel-ID bei Fehlern')
            ->addOption('error-attribute', null, InputOption::VALUE_OPTIONAL, 'Merkmals-ID für Fehlermeldungen')
            ->addOption('connection', null, InputOption::VALUE_OPTIONAL, 'ID einer vordefinierten Amagno-Verbindung aus config/amagno_connections.json')
            ->addOption('all-connections', null, InputOption::VALUE_NONE, 'Alle vordefinierten Amagno-Verbindungen verarbeiten')
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
        $runAllConnections = (bool) $input->getOption('all-connections');
        $connectionId = $input->getOption('connection') ?: null;

        if ($runAllConnections) {
            return $this->runAllConnections($input, $output);
        }

        if ($connectionId !== null) {
            $connection = $this->connectionRegistry->get($connectionId);
            $options = $this->createOptionsFromConnection($connection, $input);
        } else {
            $options = $this->createOptionsFromInput($input);
        }

        $this->performSync($options, $output);

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

    private function runAllConnections(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->connectionRegistry->hasConnections()) {
            throw new RuntimeException('Es sind keine Verbindungen in config/amagno_connections.json konfiguriert.');
        }

        foreach ($this->connectionRegistry->all() as $connection) {
            $output->writeln(sprintf('<comment>Starte Verbindung "%s"</comment>', $connection->id()));
            $options = $this->createOptionsFromConnection($connection, $input);
            $this->performSync($options, $output);
        }

        return Command::SUCCESS;
    }

    private function createOptionsFromConnection(ConnectionDefinition $connection, InputInterface $input): SyncOptions
    {
        $exportTarget = $this->resolveExportTarget($input, $connection->exportTarget());
        $limit = (int) $input->getOption('limit');
        $useCheckpoint = (bool) $input->getOption('use-checkpoint');
        $since = $this->resolveSinceDate($input->getOption('since'));

        $magnetId = $input->getOption('magnet') ?: $connection->magnetId();
        $checkpointKey = $input->getOption('checkpoint-key') ?: $magnetId;

        return new SyncOptions(
            magnetId: $magnetId,
            exportTarget: $exportTarget,
            profile: $input->getOption('matching-profile') ?: $connection->profile(),
            template: $input->getOption('template') ?: $connection->template(),
            system: $input->getOption('system') ?? $connection->system() ?? 'onprem',
            token: $input->getOption('token') ?: null,
            apiUsername: $connection->username(),
            apiPassword: $connection->password(),
            apiAuthType: $connection->authType(),
            baseUri: $connection->baseUri(),
            credentialId: $connection->credentialId(),
            connectionId: $connection->id(),
            vaultId: $input->getOption('vault') ?: $connection->vaultId(),
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
            successStampId: $input->getOption('success-stamp') ?: $connection->successStampId(),
            errorStampId: $input->getOption('error-stamp') ?: $connection->errorStampId(),
            errorAttributeId: $input->getOption('error-attribute') ?: $connection->errorAttributeId(),
            dryRun: (bool) $input->getOption('dry-run'),
            useCheckpoint: $useCheckpoint,
            checkpointName: $checkpointKey,
            batchSize: $limit,
            modifiedSince: $since
        );
    }

    private function createOptionsFromInput(InputInterface $input): SyncOptions
    {
        $magnetId = (string) $input->getOption('magnet');
        if ($magnetId === '') {
            throw new RuntimeException('Option --magnet ist erforderlich.');
        }

        $exportTarget = $this->resolveExportTarget($input);
        $limit = (int) $input->getOption('limit');
        $useCheckpoint = (bool) $input->getOption('use-checkpoint');
        $checkpointKey = $input->getOption('checkpoint-key') ?: $magnetId;
        $since = $this->resolveSinceDate($input->getOption('since'));

        return new SyncOptions(
            magnetId: $magnetId,
            exportTarget: $exportTarget,
            profile: $input->getOption('matching-profile') ?: null,
            template: $input->getOption('template') ?: null,
            system: $input->getOption('system') ?? 'onprem',
            token: $input->getOption('token') ?: null,
            apiUsername: $input->getOption('api-user') ?: null,
            apiPassword: $input->getOption('api-password') ?: null,
            apiAuthType: $input->getOption('api-auth-type') ?: null,
            baseUri: $input->getOption('base-uri') ?: null,
            credentialId: $input->getOption('credential-id') ? (int) $input->getOption('credential-id') : null,
            vaultId: $input->getOption('vault') ?: null,
            localFolder: $input->getOption('folder') ?: $connection->localFolder(),
            ftpServer: $input->getOption('ftp-server') ?: null,
            ftpUser: $input->getOption('ftp-user') ?: null,
            ftpPassword: $input->getOption('ftp-password') ?: null,
            ftpFolder: $input->getOption('ftp-folder') ?: null,
            dbHost: $input->getOption('db-host') ?: null,
            dbName: $input->getOption('db-name') ?: null,
            dbUser: $input->getOption('db-user') ?: null,
            dbPassword: $input->getOption('db-password') ?: null,
            stampId: $input->getOption('stamp-id') ?: null,
            successStampId: $input->getOption('success-stamp') ?: null,
            errorStampId: $input->getOption('error-stamp') ?: null,
            errorAttributeId: $input->getOption('error-attribute') ?: null,
            dryRun: (bool) $input->getOption('dry-run'),
            useCheckpoint: $useCheckpoint,
            checkpointName: $checkpointKey,
            batchSize: $limit,
            modifiedSince: $since
        );
    }

    private function performSync(SyncOptions $options, OutputInterface $output): void
    {
        if ($options->connectionId !== null) {
            $output->writeln(sprintf('<info>Verbindung: %s</info>', $options->connectionId));
        }

        $result = $this->fibuExportService->sync($options);
        $documentCount = $result['document_count'] ?? 0;
        $renderedCount = $result['rendered_blocks'] ?? 0;

        if ($documentCount === 0) {
            $output->writeln('<info>Keine neuen Dokumente gefunden.</info>');
        } else {
            $output->writeln(sprintf(
                '<info>%d Dokument(e) verarbeitet, %d Ausgabeblöcke erzeugt.</info>',
                $documentCount,
                $renderedCount
            ));
        }

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
    }

    private function resolveExportTarget(InputInterface $input, ?string $fallback = null): string
    {
        $exportTarget = $input->getOption('export') ?: $fallback;
        if ($exportTarget === null || $exportTarget === '') {
            throw new RuntimeException('Option --export oder eine Konfiguration ist erforderlich.');
        }

        return (string) $exportTarget;
    }
}
