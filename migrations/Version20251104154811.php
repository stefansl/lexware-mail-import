<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251104154811 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE imported_mails (id INT AUTO_INCREMENT NOT NULL, subject VARCHAR(255) NOT NULL, fromAddress VARCHAR(320) NOT NULL, messageId VARCHAR(255) DEFAULT NULL, receivedAt DATETIME NOT NULL, processed TINYINT(1) DEFAULT 0 NOT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE imported_pdfs (id INT AUTO_INCREMENT NOT NULL, originalFilename VARCHAR(255) NOT NULL, storedPath VARCHAR(512) NOT NULL, synced TINYINT(1) DEFAULT 0 NOT NULL, lexwareFileId VARCHAR(64) DEFAULT NULL, lexwareVoucherId VARCHAR(64) DEFAULT NULL, importedAt DATETIME NOT NULL, lastError LONGTEXT DEFAULT NULL, mail_id INT NOT NULL, INDEX IDX_368CDB36C8776F01 (mail_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE imported_pdfs ADD CONSTRAINT FK_368CDB36C8776F01 FOREIGN KEY (mail_id) REFERENCES imported_mails (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE imported_pdfs DROP FOREIGN KEY FK_368CDB36C8776F01');
        $this->addSql('DROP TABLE imported_mails');
        $this->addSql('DROP TABLE imported_pdfs');
    }
}
