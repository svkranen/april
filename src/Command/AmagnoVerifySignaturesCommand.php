<?php

namespace App\Command;

use App\Dto\SignatureCheckOptions;
use App\Service\Amagno\ConnectionDefinition;
use App\Service\Amagno\ConnectionRegistry;
use App\Service\SignatureCheck\AmagnoSignatureCheckService;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'amagno:verify-signatures',
    description: 'Prueft pro Dokument, ob fuer alle erwarteten Freigaben passende Unterschriften vorhanden sind.'
)]
class AmagnoVerifySignaturesCommand extends Command
{
    /** @var resource|null */
    private $lockHandle = null;

    public function __construct(
        private readonly AmagnoSignatureCheckService $signatureCheckService,
        private readonly ConnectionRegistry $connectionRegistry
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('connection', null, InputOption::VALUE_OPTIONAL, 'ID einer vordefinierten Amagno-Verbindung')
            ->addOption('all-connections', null, InputOption::VALUE_NONE, 'Alle Verbindungen mit signature_check-Block verarbeiten')
            ->addOption('magnet', null, InputOption::VALUE_OPTIONAL, 'Magnet ID')
            ->addOption('required-tag', null, InputOption::VALUE_OPTIONAL, 'Merkmals-ID fuer "Zu pruefen durch"')
            ->addOption('confirmed-tag', null, InputOption::VALUE_OPTIONAL, 'Merkmals-ID fuer "Geprueft durch"')
            ->addOption('result-attribute', null, InputOption::VALUE_OPTIONAL, 'Merkmals-ID fuer das Pruefergebnis')
            ->addOption('success-stamp', null, InputOption::VALUE_OPTIONAL, 'Stempel-ID fuer erfolgreich gepruefte Dokumente')
            ->addOption('complete-stamp', null, InputOption::VALUE_OPTIONAL, 'Stempel-ID fuer vollstaendige Dokumente')
            ->addOption('incomplete-stamp', null, InputOption::VALUE_OPTIONAL, 'Stempel-ID fuer unvollstaendige Dokumente')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Anzahl Dokumente je Lauf', 200)
            ->addOption('token', null, InputOption::VALUE_OPTIONAL, 'Amagno API Token')
            ->addOption('api-user', null, InputOption::VALUE_OPTIONAL, 'Benutzername des technischen Amagno-Kontos')
            ->addOption('api-password', null, InputOption::VALUE_OPTIONAL, 'Passwort des technischen Amagno-Kontos')
            ->addOption('base-uri', null, InputOption::VALUE_OPTIONAL, 'Amagno Base URI')
            ->addOption('credential-id', null, InputOption::VALUE_OPTIONAL, 'Credential-ID fuer den Tokenabruf')
            ->addOption('checkpoint-key', null, InputOption::VALUE_OPTIONAL, 'Eigener Checkpoint-Key')
            ->addOption('use-checkpoint', null, InputOption::VALUE_NONE, 'Nur seit dem letzten Lauf geaenderte Dokumente pruefen')
            ->addOption('since', null, InputOption::VALUE_OPTIONAL, 'ISO-Datum als Startzeitpunkt, uebersteuert den Checkpoint')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Nur pruefen, nichts zurueckschreiben');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->acquireRunLock()) {
            $output->writeln('<comment>Ein amagno:verify-signatures-Lauf ist bereits aktiv. Dieser Start wird uebersprungen.</comment>');

            return Command::SUCCESS;
        }

        try {
            $runAllConnections = (bool) $input->getOption('all-connections');
            $connectionId = $input->getOption('connection') ?: null;

            if ($runAllConnections || ($connectionId === null && !$this->connectionRegistry->hasConnections())) {
                return $this->runAllConnections($input, $output);
            }

            $options = $connectionId !== null
                ? $this->createOptionsFromConnection($this->connectionRegistry->get($connectionId), $input)
                : $this->createOptionsFromInput($input);

            $this->performCheck($options, $output);

            return Command::SUCCESS;
        } finally {
            $this->releaseRunLock();
        }
    }

    private function runAllConnections(InputInterface $input, OutputInterface $output): int
    {
        $hasChecks = false;

        foreach ($this->connectionRegistry->all() as $connection) {
            if ($connection->signatureCheck() === null) {
                continue;
            }

            $hasChecks = true;
            $output->writeln(sprintf('<comment>Pruefe Verbindung "%s"</comment>', $connection->id()));
            $this->performCheck($this->createOptionsFromConnection($connection, $input), $output);
        }

        if (!$hasChecks) {
            throw new RuntimeException('Es sind keine Verbindungen mit "signature_check" konfiguriert.');
        }

        return Command::SUCCESS;
    }

    private function createOptionsFromConnection(ConnectionDefinition $connection, InputInterface $input): SignatureCheckOptions
    {
        $signatureCheck = $connection->signatureCheck() ?? [];
        $magnetId = (string) ($input->getOption('magnet') ?: $connection->magnetId());
        $requiredTagId = (string) ($input->getOption('required-tag') ?: ($signatureCheck['required_tag'] ?? ''));
        $confirmedTagId = (string) ($input->getOption('confirmed-tag') ?: ($signatureCheck['confirmed_tag'] ?? ''));

        if ($requiredTagId === '' || $confirmedTagId === '') {
            throw new RuntimeException(sprintf(
                'Verbindung "%s" benoetigt signature_check.required_tag und signature_check.confirmed_tag.',
                $connection->id()
            ));
        }

        return new SignatureCheckOptions(
            magnetId: $magnetId,
            requiredTagId: $requiredTagId,
            confirmedTagId: $confirmedTagId,
            connectionId: $connection->id(),
            token: $input->getOption('token') ?: null,
            apiUsername: $connection->username(),
            apiPassword: $connection->password(),
            baseUri: $input->getOption('base-uri') ?: $connection->baseUri(),
            credentialId: $input->getOption('credential-id') ? (int) $input->getOption('credential-id') : $connection->credentialId(),
            resultAttributeId: $input->getOption('result-attribute') ?: ($signatureCheck['result_attribute'] ?? null),
            completeStampId: $input->getOption('success-stamp')
                ?: $input->getOption('complete-stamp')
                ?: ($signatureCheck['success_stamp'] ?? null)
                ?: ($signatureCheck['complete_stamp'] ?? null),
            incompleteStampId: $input->getOption('incomplete-stamp') ?: ($signatureCheck['incomplete_stamp'] ?? null),
            dryRun: (bool) $input->getOption('dry-run'),
            useCheckpoint: (bool) $input->getOption('use-checkpoint'),
            checkpointName: $input->getOption('checkpoint-key') ?: ($signatureCheck['checkpoint_key'] ?? $magnetId.'-signature-check'),
            batchSize: (int) $input->getOption('limit'),
            modifiedSince: $this->resolveSinceDate($input->getOption('since'))
        );
    }

    private function createOptionsFromInput(InputInterface $input): SignatureCheckOptions
    {
        $magnetId = (string) ($input->getOption('magnet') ?: '');
        $requiredTagId = (string) ($input->getOption('required-tag') ?: '');
        $confirmedTagId = (string) ($input->getOption('confirmed-tag') ?: '');

        if ($magnetId === '' || $requiredTagId === '' || $confirmedTagId === '') {
            throw new RuntimeException('Die Optionen --magnet, --required-tag und --confirmed-tag sind erforderlich.');
        }

        return new SignatureCheckOptions(
            magnetId: $magnetId,
            requiredTagId: $requiredTagId,
            confirmedTagId: $confirmedTagId,
            token: $input->getOption('token') ?: null,
            apiUsername: $input->getOption('api-user') ?: null,
            apiPassword: $input->getOption('api-password') ?: null,
            baseUri: $input->getOption('base-uri') ?: null,
            credentialId: $input->getOption('credential-id') ? (int) $input->getOption('credential-id') : null,
            resultAttributeId: $input->getOption('result-attribute') ?: null,
            completeStampId: $input->getOption('success-stamp') ?: $input->getOption('complete-stamp') ?: null,
            incompleteStampId: $input->getOption('incomplete-stamp') ?: null,
            dryRun: (bool) $input->getOption('dry-run'),
            useCheckpoint: (bool) $input->getOption('use-checkpoint'),
            checkpointName: $input->getOption('checkpoint-key') ?: $magnetId.'-signature-check',
            batchSize: (int) $input->getOption('limit'),
            modifiedSince: $this->resolveSinceDate($input->getOption('since'))
        );
    }

    private function resolveSinceDate(?string $sinceOption): ?DateTimeImmutable
    {
        if ($sinceOption) {
            return new DateTimeImmutable($sinceOption);
        }

        return null;
    }

    private function performCheck(SignatureCheckOptions $options, OutputInterface $output): void
    {
        if ($options->connectionId !== null) {
            $output->writeln(sprintf('<info>Verbindung: %s</info>', $options->connectionId));
        }

        $result = $this->signatureCheckService->check($options);

        $output->writeln(sprintf(
            '<info>%d Dokument(e) geprueft, %d vollstaendig, %d unvollstaendig.</info>',
            $result['document_count'],
            $result['complete_count'],
            $result['incomplete_count']
        ));

        if (!empty($result['checkpoint_from'])) {
            $output->writeln(sprintf('<info>Checkpoint verwendet: %s</info>', $result['checkpoint_from']));
        }
        if (!empty($result['checkpoint_updated'])) {
            $output->writeln(sprintf('<info>Checkpoint gespeichert: %s</info>', $result['checkpoint_updated']));
        }

        foreach ($result['documents'] as $document) {
            $line = sprintf(
                '- %s | Nummer: %s | %s',
                $document['document_id'],
                $document['document_number'] ?? 'n/a',
                $document['message']
            );

            $output->writeln($document['complete'] ? '<info>'.$line.'</info>' : '<comment>'.$line.'</comment>');
        }
    }

    private function acquireRunLock(): bool
    {
        $lockDir = \dirname(__DIR__, 2).'/var/lock';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            throw new RuntimeException(sprintf('Lock-Verzeichnis "%s" konnte nicht erstellt werden.', $lockDir));
        }

        $lockPath = $lockDir.'/amagno-verify-signatures.lock';
        $handle = fopen($lockPath, 'c+');
        if ($handle === false) {
            throw new RuntimeException(sprintf('Lock-Datei "%s" konnte nicht geoeffnet werden.', $lockPath));
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) getmypid());
        fflush($handle);
        $this->lockHandle = $handle;

        return true;
    }

    private function releaseRunLock(): void
    {
        if (!is_resource($this->lockHandle)) {
            return;
        }

        flock($this->lockHandle, LOCK_UN);
        fclose($this->lockHandle);
        $this->lockHandle = null;
    }
}
