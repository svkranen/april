<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260530090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add event phase to persisted Intelligence process events.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE intelligence_process_event ADD event_phase VARCHAR(16) DEFAULT 'after' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intelligence_process_event DROP event_phase');
    }
}
