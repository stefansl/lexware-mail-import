<?php

declare(strict_types=1);

namespace App\Mail;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webklex\PHPIMAP\Message;

final class FromAddressResolver
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.importer')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolve(Message $msg): string
    {
        // Phase 1: Structured "From" field (array/traversable of address objects/arrays).
        $email = $this->fromStructured($msg);
        if (null !== $email) {
            return $email;
        }

        // Phase 2: Raw header fallback (regex extraction from "From" header).
        $email = $this->fromHeader($msg);
        if (null !== $email) {
            return $email;
        }

        // Phase 3: Final fallback (non-exceptional; mail may lack a valid address).
        $this->logger->notice('sender unknown, using fallback', [
            'subject' => (string) ($msg->getSubject() ?? ''),
            'id' => $this->safeMessageId($msg),
        ]);

        return 'unknown@example.com';
    }

    /**
     * Try to extract from Webklex' structured "From".
     * Returns null if not extractable (not exceptional).
     */
    private function fromStructured(Message $msg): ?string
    {
        try {
            $from = $msg->getFrom();
        } catch (\Throwable $e) {
            $this->logger->debug('structured from retrieval failed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($from instanceof \Traversable) {
            $from = iterator_to_array($from);
        }
        if (!is_array($from) || [] === $from) {
            return null; // guard: nothing to parse
        }

        foreach ($from as $entry) {
            $email = $this->extractEmailFromEntry($entry);
            if (null !== $email) {
                return $email;
            }
        }

        return null;
    }

    /**
     * Extract from the raw "From" header using a permissive regex.
     * Returns null if header missing or unparsable (non-exceptional).
     */
    private function fromHeader(Message $msg): ?string
    {
        try {
            $hdr = $msg->getHeader();
        } catch (\Throwable $e) {
            $this->logger->debug('header retrieval failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (null === $hdr) {
            return null; // guard: no headers at all
        }

        // Webklex typically returns an Attribute-like object with getValue()
        try {
            $fromHeader = $hdr->get('from');
        } catch (\Throwable $e) {
            $this->logger->debug('from header access failed', ['error' => $e->getMessage()]);

            return null;
        }

        // Safely obtain the actual header string: prefer getValue(), else string-cast
        $value = null;
        if (\is_object($fromHeader) && \method_exists($fromHeader, 'getValue')) {
            $value = $fromHeader->getValue();
        } elseif (\is_string($fromHeader)) {
            $value = $fromHeader;
        } else {
            // Fallback: best-effort string cast (covers objects with __toString)
            $value = (string) $fromHeader;
        }

        if (!\is_string($value) || '' === $value) {
            // As a last resort, try the entire header block (toString) and grep the From-line
            try {
                $all = \method_exists($hdr, 'toString') ? $hdr->toString() : (string) $hdr;
                if (\is_string($all) && '' !== $all) {
                    // naive From-line extraction
                    if (\preg_match('/^From:\s*(.+)$/im', $all, $m)) {
                        $value = $m[1] ?? '';
                    }
                }
            } catch (\Throwable) {
                // ignore; return null below
            }
        }

        if (!\is_string($value) || '' === $value) {
            return null;
        }

        // Extract the first RFC-ish email
        if (\preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $m)) {
            return \strtolower($m[0]);
        }

        // Not exceptional; log for diagnostics and return null to allow fallback.
        $this->logger->info('from header present but no email found', ['raw' => $value]);

        return null;
    }

    /**
     * Normalize known Webklex shapes into an email; returns null if not usable.
     * Combines related conditions to reduce redundancy.
     */
    private function extractEmailFromEntry(mixed $entry): ?string
    {
        $email = null;

        // Common object shapes: ->mail, ->address, or mailbox+host
        if (is_object($entry)) {
            $email = $entry->mail ?? $entry->address ?? null;

            if (null === $email && isset($entry->mailbox, $entry->host)
                && is_string($entry->mailbox) && '' !== $entry->mailbox
                && is_string($entry->host) && '' !== $entry->host) {
                $email = $entry->mailbox.'@'.$entry->host;
            }
        }

        // Array shapes: ['mail'|'address'] or ['mailbox' + 'host']
        if (null === $email && is_array($entry)) {
            $email = $entry['mail'] ?? $entry['address']
                ?? (isset($entry['mailbox'], $entry['host']) && is_string($entry['mailbox']) && is_string($entry['host'])
                    ? $entry['mailbox'].'@'.$entry['host']
                    : null);
        }

        return $this->normalizeEmail($email);
    }

    /** Normalize and lightly validate an email; returns null if not usable (not exceptional). */
    private function normalizeEmail(?string $email): ?string
    {
        if (!is_string($email) || '' === $email || !str_contains($email, '@')) {
            return null; // guard: clearly not an email
        }

        $email = trim($email, " \t\n\r\0\x0B<>\"");
        if ('' === $email || !str_contains($email, '@')) {
            return null;
        }

        return strtolower($email);
    }

    private function safeMessageId(Message $msg): ?string
    {
        try {
            $mid = $msg->getMessageId();

            return is_string($mid) && '' !== $mid ? $mid : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
