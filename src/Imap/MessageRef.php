<?php
declare(strict_types=1);

namespace App\Imap;

/**
 * Light-weight reference to an IMAP message, decoupled from the vendor object.
 */
final class MessageRef
{
    public function __construct(
        public readonly object $vendorMessage,
        public readonly ?int $uid,
        public readonly string $subject,
        public readonly string $fromAddress,
        public readonly ?string $messageId,
        public readonly \DateTimeImmutable $receivedAt,
        public readonly string $mailbox,
    ) {}
}
