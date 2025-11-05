<?php
declare(strict_types=1);

namespace App\Imap;

use App\DTO\ImapFetchFilter;

/**
 * Fetches messages via Webklex and yields MessageRef objects.
 * Now with robust folder resolution and clear errors.
 */
final class WebklexMessageFetcher
{
    public function __construct(
        private readonly ImapConnectionFactory $factory,
        private readonly string $defaultMailbox,
        private readonly string $defaultSearch
    ) {
        if ($this->defaultMailbox === '') {
            throw new \RuntimeException('IMAP_MAILBOX must be set (e.g., "INBOX").');
        }
    }

    /** @return \Generator<MessageRef> */
    public function fetch(ImapFetchFilter $filter): \Generator
    {
        $client  = $this->factory->create();
        $wanted  = $filter->mailbox ?: $this->defaultMailbox;

        $folder = $this->resolveFolder($client, $wanted);

        $q = $folder->query();
        $since = $filter->since ?? new \DateTimeImmutable('-7 days');
        $q->since($since);

        $onlyUnseen = $filter->onlyUnseen;
        if ($onlyUnseen === true || ($onlyUnseen === null && \strtoupper($this->defaultSearch) === 'UNSEEN')) {
            $q->unseen();
        } elseif ($onlyUnseen === false) {
            $q->seen();
        }

        $messages = $q->get();
        $count = 0;
        $limit = $filter->limit ?? 50;

        foreach ($messages as $msg) {
            if ($count >= $limit) break;

            $subjRaw = (string)($msg->getSubject() ?? '');
            $subject = \function_exists('mb_decode_mimeheader') ? \mb_decode_mimeheader($subjRaw) : $subjRaw;

            if ($filter->subjectContains && \stripos($subject, $filter->subjectContains) === false) {
                continue;
            }

            $from = $this->extractFrom($msg);
            if ($filter->fromContains && \stripos($from, $filter->fromContains) === false) {
                continue;
            }

            $messageId = $this->safeGetMessageId($msg);
            $date      = $this->safeGetDate($msg);
            $uid       = $this->safeGetUid($msg);

            yield new MessageRef(
                vendorMessage: $msg,
                uid: $uid,
                subject: $subject !== '' ? $subject : '(no subject)',
                fromAddress: $from !== '' ? $from : 'unknown@example.com',
                messageId: $messageId,
                receivedAt: $date,
                mailbox: $folder->name ?? $wanted
            );

            $count++;
        }
    }

    /**
     * Tries to resolve a folder by name with sensible fallbacks:
     *  - exact getFolder($name)
     *  - common variants (case-insensitive, with/without "INBOX." prefix)
     *  - fallback to INBOX
     * Throws RuntimeException with available folders if nothing matches.
     */
    private function resolveFolder(object $client, string $wanted): object
    {
        // try exact
        try {
            $folder = $client->getFolder($wanted);
            if ($folder !== null) {
                return $folder;
            }
        } catch (\Throwable) {
            // swallow and continue to search
        }

        // list and try case-insensitive match & common variants
        $available = [];
        try {
            $folders = $client->getFolders(); // iterable
            foreach ($folders as $f) {
                $name = (string)($f->name ?? $f->full_name ?? '');
                if ($name !== '') {
                    $available[] = $name;
                }
                // case-insensitive match
                if (\strcasecmp($name, $wanted) === 0) {
                    return $f;
                }
                // try with/without INBOX. prefix
                if (\str_starts_with(\strtoupper($name), 'INBOX.')
                    && \strcasecmp(\substr($name, 6), $wanted) === 0) {
                    return $f;
                }
                if (\strtoupper($wanted) === 'INBOX' && \strtoupper($name) === 'INBOX') {
                    return $f;
                }
            }
        } catch (\Throwable) {
            // ignore listing errors; we'll try INBOX fallback
        }

        // fallback to INBOX explicitly
        try {
            $inbox = $client->getFolder('INBOX');
            if ($inbox !== null) {
                return $inbox;
            }
        } catch (\Throwable) {
            // ignore
        }

        $hint = $available ? (' Available folders: ' . \implode(', ', \array_slice($available, 0, 20)) . (count($available) > 20 ? ', …' : '')) : '';
        throw new \RuntimeException(sprintf('IMAP mailbox "%s" not found.%s', $wanted, $hint));
    }

    private function extractFrom(object $msg): string
    {
        try {
            $list = $msg->getFrom();
            if ($list instanceof \Traversable) $list = iterator_to_array($list);
            if (\is_array($list)) {
                foreach ($list as $p) {
                    if (\is_object($p)) {
                        $email = $p->mail ?? $p->address ?? null;
                        if (!$email && isset($p->mailbox, $p->host) && $p->mailbox && $p->host) {
                            $email = $p->mailbox.'@'.$p->host;
                        }
                        if (\is_string($email) && $email !== '') return $email;
                    }
                }
            }
        } catch (\Throwable) {}
        try {
            $raw = $msg->getHeader()?->get('from');
            if ($raw) {
                $value = \method_exists($raw, 'getValue') ? $raw->getValue() : $raw->getRaw();
                if (\is_string($value) && $value !== '') {
                    if (\preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $m)) return $m[0];
                    return $value;
                }
            }
        } catch (\Throwable) {}
        return '';
    }

    private function safeGetMessageId(object $msg): ?string
    {
        try { $mid = $msg->getMessageId(); return \is_string($mid) && $mid !== '' ? $mid : null; }
        catch (\Throwable) { return null; }
    }

    private function safeGetDate(object $msg): \DateTimeImmutable
    {
        try {
            $raw = $msg->getDate();
            if ($raw instanceof \DateTimeInterface) return \DateTimeImmutable::createFromInterface($raw);
            if (\is_string($raw) && $raw !== '') return new \DateTimeImmutable($raw);
        } catch (\Throwable) {}
        return new \DateTimeImmutable();
    }

    private function safeGetUid(object $msg): ?int
    {
        try { return \method_exists($msg, 'getUid') ? (int)$msg->getUid() : null; }
        catch (\Throwable) { return null; }
    }
}
