<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251209172336 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('activity_log')) {
            $this->addSql('CREATE TABLE activity_log (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, role VARCHAR(255) NOT NULL, action VARCHAR(100) NOT NULL, entity_type VARCHAR(255) DEFAULT NULL, entity_id INT DEFAULT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_FD06F647A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
            $this->addSql('ALTER TABLE activity_log ADD CONSTRAINT FK_FD06F647A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        }

        if ($schema->hasTable('orders')) {
            $ordersTable = $schema->getTable('orders');
            if ($ordersTable->hasColumn('processed_by_id') && !$ordersTable->hasForeignKey('FK_E52FFDEE2FFD4FD3')) {
                $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE2FFD4FD3 FOREIGN KEY (processed_by_id) REFERENCES user (id) ON DELETE SET NULL');
            }
        }

        if ($schema->hasTable('user')) {
            $userTable = $schema->getTable('user');
            $columns = [];
            if (!$userTable->hasColumn('email')) {
                $columns[] = 'ADD email VARCHAR(255) DEFAULT NULL';
            }
            if (!$userTable->hasColumn('full_name')) {
                $columns[] = 'ADD full_name VARCHAR(255) DEFAULT NULL';
            }
            if (!$userTable->hasColumn('created_at')) {
                $columns[] = 'ADD created_at DATETIME DEFAULT NULL';
            }
            if (!$userTable->hasColumn('updated_at')) {
                $columns[] = 'ADD updated_at DATETIME DEFAULT NULL';
            }

            if ($columns !== []) {
                $this->addSql('ALTER TABLE user ' . implode(', ', $columns));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activity_log DROP FOREIGN KEY FK_FD06F647A76ED395');
        $this->addSql('DROP TABLE activity_log');
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_E52FFDEE2FFD4FD3');
        $this->addSql('ALTER TABLE user DROP email, DROP full_name, DROP created_at, DROP updated_at');
    }
}
