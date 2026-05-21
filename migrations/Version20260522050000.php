<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260522050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store uploaded customization design snapshots';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('customization_request') && !$schema->getTable('customization_request')->hasColumn('design_snapshot')) {
            $this->addSql('ALTER TABLE customization_request ADD design_snapshot LONGTEXT DEFAULT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('customization_request') && $schema->getTable('customization_request')->hasColumn('design_snapshot')) {
            $this->addSql('ALTER TABLE customization_request DROP design_snapshot');
        }
    }
}
