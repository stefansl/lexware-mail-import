<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251105130530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE imported_mails ADD from_address VARCHAR(255) NOT NULL, DROP fromAddress, DROP processed, CHANGE subject subject VARCHAR(512) NOT NULL, CHANGE messageId message_id VARCHAR(255) DEFAULT NULL, CHANGE receivedAt received_at DATETIME NOT NULL');
        $this->addSql('ALTER TABLE imported_pdfs ADD lexware_file_id VARCHAR(100) DEFAULT NULL, ADD lexware_voucher_id VARCHAR(100) DEFAULT NULL, ADD size INT UNSIGNED DEFAULT NULL, ADD mime VARCHAR(100) DEFAULT NULL, ADD file_hash VARCHAR(64) DEFAULT NULL, DROP lexwareFileId, DROP lexwareVoucherId, CHANGE synced synced TINYINT(1) NOT NULL, CHANGE originalFilename original_filename VARCHAR(255) NOT NULL, CHANGE storedPath stored_path VARCHAR(512) NOT NULL, CHANGE importedAt imported_at DATETIME NOT NULL, CHANGE lastError last_error LONGTEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE imported_mails ADD fromAddress VARCHAR(320) NOT NULL, ADD processed TINYINT(1) DEFAULT 0 NOT NULL, DROP from_address, CHANGE subject subject VARCHAR(255) NOT NULL, CHANGE message_id messageId VARCHAR(255) DEFAULT NULL, CHANGE received_at receivedAt DATETIME NOT NULL');
        $this->addSql('ALTER TABLE imported_pdfs ADD lexwareVoucherId VARCHAR(64) DEFAULT NULL, DROP lexware_file_id, DROP lexware_voucher_id, DROP size, DROP mime, CHANGE synced synced TINYINT(1) DEFAULT 0 NOT NULL, CHANGE original_filename originalFilename VARCHAR(255) NOT NULL, CHANGE stored_path storedPath VARCHAR(512) NOT NULL, CHANGE file_hash lexwareFileId VARCHAR(64) DEFAULT NULL, CHANGE imported_at importedAt DATETIME NOT NULL, CHANGE last_error lastError LONGTEXT DEFAULT NULL');
    }
}
