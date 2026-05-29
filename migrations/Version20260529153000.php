<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260529153000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add SSE realtime events and Firebase device tokens';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('realtime_event')) {
            $this->addSql('CREATE TABLE realtime_event (id INT AUTO_INCREMENT NOT NULL, event_type VARCHAR(80) NOT NULL, payload JSON NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_REALTIME_EVENT_CREATED_AT (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

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

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('realtime_event')) {
            $this->addSql('DROP TABLE realtime_event');
        }

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
}
