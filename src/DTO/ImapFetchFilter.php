<?php
declare(strict_types=1);

namespace App\DTO;

/**
 * Immutable filter for IMAP fetching.
 */
final class ImapFetchFilter
{
    public function __construct(
        public readonly ?\DateTimeImmutable $since = null,
        public readonly ?int $limit = null,
        public readonly ?string $fromContains = null,
        public readonly ?string $subjectContains = null,
        public readonly ?string $mailbox = null,
        public readonly ?bool $onlyUnseen = null, // true = unseen, false = seen, null = keep default from config
    ) {}
}
