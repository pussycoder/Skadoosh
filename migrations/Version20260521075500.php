<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260521075500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customization requests for customer designs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customization_request (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, assigned_to_id INT DEFAULT NULL, product_type VARCHAR(40) NOT NULL, base_color VARCHAR(100) DEFAULT NULL, size VARCHAR(50) DEFAULT NULL, placement VARCHAR(80) DEFAULT NULL, design_description LONGTEXT NOT NULL, notes LONGTEXT DEFAULT NULL, status VARCHAR(40) NOT NULL, staff_response LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_3B8B8E659395C3F3 (customer_id), INDEX IDX_3B8B8E65F4BD7827 (assigned_to_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE customization_request ADD CONSTRAINT FK_3B8B8E659395C3F3 FOREIGN KEY (customer_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE customization_request ADD CONSTRAINT FK_3B8B8E65F4BD7827 FOREIGN KEY (assigned_to_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customization_request DROP FOREIGN KEY FK_3B8B8E659395C3F3');
        $this->addSql('ALTER TABLE customization_request DROP FOREIGN KEY FK_3B8B8E65F4BD7827');
        $this->addSql('DROP TABLE customization_request');
    }
}
