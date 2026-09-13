<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260913120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add explicit process baseline to template version mapping';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intelligence_process_version ADD template_version VARCHAR(128) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE intelligence_process_version DROP template_version');
    }
}
