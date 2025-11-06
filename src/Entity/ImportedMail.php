<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(
    name: 'imported_mails',
    uniqueConstraints: [
        // Allow dedupe by message-id; multiple NULLs are allowed by MySQL/MariaDB
        new ORM\UniqueConstraint(name: 'uniq_imported_mails_message_id', columns: ['message_id']),
    ]
)]
class ImportedMail
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'subject', type: 'string', length: 512)]
    private string $subject;

    #[ORM\Column(name: 'from_address', type: 'string', length: 255)]
    private string $fromAddress;

    #[ORM\Column(name: 'message_id', type: 'string', length: 255, nullable: true)]
    private ?string $messageId = null;

    #[ORM\Column(name: 'received_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $receivedAt;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function setSubject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function getFromAddress(): string
    {
        return $this->fromAddress;
    }

    public function setFromAddress(string $from): self
    {
        $this->fromAddress = $from;

        return $this;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function setMessageId(?string $mid): self
    {
        $this->messageId = $mid;

        return $this;
    }

    public function getReceivedAt(): \DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $dt): self
    {
        $this->receivedAt = $dt;

        return $this;
    }
}
