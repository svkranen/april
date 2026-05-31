<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair Amagno event timestamps from local time to UTC while keeping timezone-less datetime columns.';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->amagnoLocalEventColumns() as $table => $columns) {
            foreach ($columns as $column) {
                $this->addSql(sprintf(
                    'UPDATE %s SET %s = (%s AT TIME ZONE %s) AT TIME ZONE %s WHERE %s IS NOT NULL',
                    $table,
                    $column,
                    $column,
                    $this->quote('Europe/Berlin'),
                    $this->quote('UTC'),
                    $column
                ));
            }
        }

        foreach ($this->utcDocumentedColumns() as $table => $columns) {
            foreach ($columns as $column) {
                $this->addSql(sprintf(
                    'COMMENT ON COLUMN %s.%s IS %s',
                    $table,
                    $column,
                    $this->quote('UTC timestamp stored without database timezone offset.')
                ));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach ($this->utcDocumentedColumns() as $table => $columns) {
            foreach ($columns as $column) {
                $this->addSql(sprintf(
                    'COMMENT ON COLUMN %s.%s IS NULL',
                    $table,
                    $column
                ));
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function amagnoLocalEventColumns(): array
    {
        return [
            'intelligence_process_event' => ['occurred_at'],
            'intelligence_process_instance' => ['started_at', 'last_event_at', 'ended_at'],
            'intelligence_context_snapshot' => ['occurred_at'],
            'intelligence_incoming_event' => ['occurred_at'],
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function utcDocumentedColumns(): array
    {
        return [
            'intelligence_process_event' => ['occurred_at', 'received_at'],
            'intelligence_process_instance' => ['started_at', 'last_event_at', 'ended_at', 'created_at', 'updated_at'],
            'intelligence_context_snapshot' => ['captured_at', 'occurred_at', 'loaded_at'],
            'intelligence_incoming_event' => ['occurred_at', 'received_at', 'processed_at', 'created_at', 'updated_at'],
        ];
    }

    private function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
