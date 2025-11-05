<?php
declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'imported_pdfs',
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'uniq_imported_pdfs_file_hash', columns: ['file_hash']),
    ],
    indexes: [
        new ORM\Index(name: 'idx_imported_pdfs_mail', columns: ['mail_id']),
    ]
)]
class ImportedPdf
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: ImportedMail::class)]
    #[ORM\JoinColumn(name: 'mail_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ImportedMail $mail;

    #[ORM\Column(name: 'original_filename', type: 'string', length: 255)]
    private string $originalFilename;

    #[ORM\Column(name: 'stored_path', type: 'string', length: 512)]
    private string $storedPath;

    #[ORM\Column(name: 'imported_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $importedAt;

    #[ORM\Column(name: 'synced', type: 'boolean')]
    private bool $synced = false;

    #[ORM\Column(name: 'lexware_file_id', type: 'string', length: 100, nullable: true)]
    private ?string $lexwareFileId = null;

    #[ORM\Column(name: 'lexware_voucher_id', type: 'string', length: 100, nullable: true)]
    private ?string $lexwareVoucherId = null;

    #[ORM\Column(name: 'last_error', type: 'text', nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(name: 'size', type: 'integer', nullable: true, options: ['unsigned' => true])]
    private ?int $size = null;

    #[ORM\Column(name: 'mime', type: 'string', length: 100, nullable: true)]
    private ?string $mime = null;

    #[ORM\Column(name: 'file_hash', type: 'string', length: 64, nullable: true)]
    private ?string $fileHash = null;

    public function getId(): ?int { return $this->id; }

    public function getMail(): ImportedMail { return $this->mail; }
    public function setMail(ImportedMail $mail): self { $this->mail = $mail; return $this; }

    public function getOriginalFilename(): string { return $this->originalFilename; }
    public function setOriginalFilename(string $name): self { $this->originalFilename = $name; return $this; }

    public function getStoredPath(): string { return $this->storedPath; }
    public function setStoredPath(string $path): self { $this->storedPath = $path; return $this; }

    public function getImportedAt(): \DateTimeImmutable { return $this->importedAt; }
    public function setImportedAt(\DateTimeImmutable $at): self { $this->importedAt = $at; return $this; }

    public function isSynced(): bool { return $this->synced; }
    public function setSynced(bool $synced): self { $this->synced = $synced; return $this; }

    public function getLexwareFileId(): ?string { return $this->lexwareFileId; }
    public function setLexwareFileId(?string $id): self { $this->lexwareFileId = $id; return $this; }

    public function getLexwareVoucherId(): ?string { return $this->lexwareVoucherId; }
    public function setLexwareVoucherId(?string $id): self { $this->lexwareVoucherId = $id; return $this; }

    public function getLastError(): ?string { return $this->lastError; }
    public function setLastError(?string $e): self { $this->lastError = $e; return $this; }

    public function getSize(): ?int { return $this->size; }
    public function setSize(?int $size): self { $this->size = $size; return $this; }

    public function getMime(): ?string { return $this->mime; }
    public function setMime(?string $mime): self { $this->mime = $mime; return $this; }

    public function getFileHash(): ?string { return $this->fileHash; }
    public function setFileHash(?string $hash): self { $this->fileHash = $hash; return $this; }
}
