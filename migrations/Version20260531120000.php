<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260531120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add event and load timestamps to Intelligence context snapshots.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intelligence_context_snapshot ADD occurred_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE intelligence_context_snapshot ADD loaded_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE intelligence_context_snapshot ADD incoming_event_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE intelligence_context_snapshot ADD freshness_seconds INT DEFAULT NULL');
        $this->addSql('ALTER TABLE intelligence_context_snapshot ADD is_fresh_for_decision_check BOOLEAN DEFAULT NULL');
        $this->addSql('UPDATE intelligence_context_snapshot SET loaded_at = captured_at WHERE loaded_at IS NULL');
        $this->addSql('UPDATE intelligence_context_snapshot SET freshness_seconds = EXTRACT(EPOCH FROM (loaded_at - occurred_at))::INT WHERE loaded_at IS NOT NULL AND occurred_at IS NOT NULL');
        $this->addSql('ALTER TABLE intelligence_context_snapshot ALTER loaded_at SET NOT NULL');
        $this->addSql('CREATE INDEX idx_intelligence_context_snapshot_incoming_event_id ON intelligence_context_snapshot (incoming_event_id)');
        $this->addSql('CREATE INDEX idx_intelligence_context_snapshot_occurred_at ON intelligence_context_snapshot (occurred_at)');
        $this->addSql('CREATE INDEX idx_intelligence_context_snapshot_loaded_at ON intelligence_context_snapshot (loaded_at)');
        $this->addSql('CREATE INDEX idx_intelligence_context_snapshot_fresh_for_decision ON intelligence_context_snapshot (is_fresh_for_decision_check)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_intelligence_context_snapshot_fresh_for_decision');
        $this->addSql('DROP INDEX idx_intelligence_context_snapshot_loaded_at');
        $this->addSql('DROP INDEX idx_intelligence_context_snapshot_occurred_at');
        $this->addSql('DROP INDEX idx_intelligence_context_snapshot_incoming_event_id');
        $this->addSql('ALTER TABLE intelligence_context_snapshot DROP is_fresh_for_decision_check');
        $this->addSql('ALTER TABLE intelligence_context_snapshot DROP freshness_seconds');
        $this->addSql('ALTER TABLE intelligence_context_snapshot DROP incoming_event_id');
        $this->addSql('ALTER TABLE intelligence_context_snapshot DROP loaded_at');
        $this->addSql('ALTER TABLE intelligence_context_snapshot DROP occurred_at');
    }
}
