<?php

namespace App\Service\Export;

use App\Dto\RenderedBlock;
use App\Dto\SyncOptions;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use RuntimeException;

class SqlExporter implements ExporterInterface
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function supports(string $target): bool
    {
        return $target === 'sql';
    }

    /**
     * @param RenderedBlock[] $blocks
     */
    public function export(array $blocks, SyncOptions $options, string $templateName): void
    {
        foreach (['dbHost','dbName','dbUser','dbPassword'] as $property) {
            if ($options->{$property} === null) {
                throw new RuntimeException(sprintf('SQL Option "%s" fehlt.', $property));
            }
        }

        try {
            $dsn = sprintf('dblib:host=%s;dbname=%s', $options->dbHost, $options->dbName);
            $pdo = new PDO($dsn, $options->dbUser, $options->dbPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->logger->info('SQL Verbindung aufgebaut', ['dsn' => $dsn]);
        } catch (PDOException $exception) {
            throw new RuntimeException('SQL Verbindung fehlgeschlagen: '.$exception->getMessage(), 0, $exception);
        }

        foreach ($blocks as $block) {
            if ($block->asExcel) {
                throw new RuntimeException('SQL Export unterstützt keine Excel-Templates.');
            }
            $statements = array_filter(array_map('trim', explode(';', $block->content)));
            foreach ($statements as $statement) {
                try {
                    $pdo->exec($statement);
                } catch (PDOException $exception) {
                    $this->logger->error('SQL Statement fehlgeschlagen', ['statement' => $statement, 'error' => $exception->getMessage()]);
                    throw $exception;
                }
            }
        }
    }
}
