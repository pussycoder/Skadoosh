<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260527062000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove Firebase Cloud Messaging token columns from user table';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('user')) {
            return;
        }

        $table = $schema->getTable('user');
        if ($table->hasColumn('fcm_token')) {
            $this->addSql('ALTER TABLE user DROP fcm_token');
        }
        if ($table->hasColumn('fcm_platform')) {
            $this->addSql('ALTER TABLE user DROP fcm_platform');
        }
        if ($table->hasColumn('fcm_token_updated_at')) {
            $this->addSql('ALTER TABLE user DROP fcm_token_updated_at');
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('user')) {
            return;
        }

        $table = $schema->getTable('user');
        if (!$table->hasColumn('fcm_token')) {
            $this->addSql('ALTER TABLE user ADD fcm_token LONGTEXT DEFAULT NULL');
        }
        if (!$table->hasColumn('fcm_platform')) {
            $this->addSql('ALTER TABLE user ADD fcm_platform VARCHAR(30) DEFAULT NULL');
        }
        if (!$table->hasColumn('fcm_token_updated_at')) {
            $this->addSql('ALTER TABLE user ADD fcm_token_updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        }
    }
}
