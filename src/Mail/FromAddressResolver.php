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
        // Guard: fetch header container
        try {
            $hdr = $msg->getHeader();
        } catch (\Throwable $e) {
            $this->logger->debug('header retrieval failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (null === $hdr) {
            return null; // no headers present
        }

        // Helper to read a single header value safely without string-casting the container
        $readHeaderValue = static function ($headerObj): ?string {
            // Webklex returns an Attribute-like object with getValue() for a single field
            if (\is_object($headerObj) && \method_exists($headerObj, 'getValue')) {
                $val = $headerObj->getValue();

                return \is_string($val) && '' !== $val ? $val : null;
            }

            // Some versions may return a scalar already
            if (\is_string($headerObj) && '' !== $headerObj) {
                return $headerObj;
            }

            // Some versions may return an array of values; try the first non-empty string
            if (\is_array($headerObj)) {
                foreach ($headerObj as $v) {
                    if (\is_string($v) && '' !== $v) {
                        return $v;
                    }
                    if (\is_object($v) && \method_exists($v, 'getValue')) {
                        $vv = $v->getValue();
                        if (\is_string($vv) && '' !== $vv) {
                            return $vv;
                        }
                    }
                }
            }

            return null;
        };

        // Try primary "from", then fall back to "reply-to" and "sender"
        $candidates = ['from', 'reply-to', 'sender'];
        $value = null;

        foreach ($candidates as $name) {
            try {
                $h = $hdr->get($name);
            } catch (\Throwable) {
                $h = null; // header field not accessible; continue trying
            }
            $value = $readHeaderValue($h);
            if (\is_string($value) && '' !== $value) {
                break;
            }
        }

        if (!\is_string($value) || '' === $value) {
            return null; // nothing usable found; caller will apply fallback
        }

        // Extract the first RFC-ish email from the header value
        if (\preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $m)) {
            return \strtolower($m[0]);
        }

        // Not exceptional; log for diagnostics and return null to allow fallback.
        $this->logger->info('from-like header present but no email found', ['raw' => $value]);

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
