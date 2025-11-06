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
    ) {}

    public function resolve(Message $msg): string
    {
        // Phase 1: Structured "From" field (array/traversable of address objects/arrays).
        $email = $this->fromStructured($msg);
        if ($email !== null) {
            return $email;
        }

        // Phase 2: Raw header fallback (regex extraction from "From" header).
        $email = $this->fromHeader($msg);
        if ($email !== null) {
            return $email;
        }

        // Phase 3: Final fallback (non-exceptional; mail may lack a valid address).
        $this->logger->notice('sender unknown, using fallback', [
            'subject' => (string)($msg->getSubject() ?? ''),
            'id'      => $this->safeMessageId($msg),
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
        if (!is_array($from) || $from === []) {
            return null; // guard: nothing to parse
        }

        foreach ($from as $entry) {
            $email = $this->extractEmailFromEntry($entry);
            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    /**
     * Extract from the raw "From" header using a permissive regex.
     * Returns null if header missing or unparsable (not exceptional).
     */
    private function fromHeader(Message $msg): ?string
    {
        try {
            $hdr = $msg->getHeader();
        } catch (\Throwable $e) {
            $this->logger->debug('header retrieval failed', ['error' => $e->getMessage()]);
            return null;
        }

        if ($hdr === null) {
            return null; // guard: no headers at all
        }

        $fromHeader = null;
        try {
            $fromHeader = $hdr->get('from');
        } catch (\Throwable $e) {
            $this->logger->debug('from header access failed', ['error' => $e->getMessage()]);
            return null;
        }

        if (!$fromHeader) {
            return null; // guard: no from header present
        }

        $value = \method_exists($fromHeader, 'getValue') ? $fromHeader->getValue() : $fromHeader->getRaw();
        if (!is_string($value) || $value === '') {
            return null; // guard: empty or non-string header value
        }

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $m)) {
            return strtolower($m[0]); // success
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

            if ($email === null && isset($entry->mailbox, $entry->host)
                && is_string($entry->mailbox) && $entry->mailbox !== ''
                && is_string($entry->host)    && $entry->host    !== '') {
                $email = $entry->mailbox.'@'.$entry->host;
            }
        }

        // Array shapes: ['mail'|'address'] or ['mailbox' + 'host']
        if ($email === null && is_array($entry)) {
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
        if (!is_string($email) || $email === '' || !str_contains($email, '@')) {
            return null; // guard: clearly not an email
        }

        $email = trim($email, " \t\n\r\0\x0B<>\"");
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        return strtolower($email);
    }

    private function safeMessageId(Message $msg): ?string
    {
        try {
            $mid = $msg->getMessageId();
            return is_string($mid) && $mid !== '' ? $mid : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
