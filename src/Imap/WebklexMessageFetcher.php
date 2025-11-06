<?php
declare(strict_types=1);

namespace App\Imap;

use App\Contract\MessageFetcherInterface;
use App\DTO\ImapFetchFilter;
use App\Mail\FromAddressResolver;
use DateTimeImmutable;
use DateTimeInterface;
use Generator;
use RuntimeException;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Message;

use function is_int;
use function is_iterable;
use function is_string;

/**
 * Fetches messages via Webklex and yields MessageReference objects.
 * - Applies mailbox, seen/unseen, since, hard limit, and optional client-side filters.
 * - Uses FromAddressResolver for robust sender extraction.
 * - Guard clauses for early validation.
 */
final class WebklexMessageFetcher implements MessageFetcherInterface
{
    public function __construct(
        private readonly ImapConnectionFactory $factory,
        private readonly string $defaultMailbox,
        private readonly string $defaultSearch,
        private readonly FromAddressResolver $fromResolver,
    ) {
        // Guard: default mailbox must be provided
        if ($this->defaultMailbox === '') {
            throw new RuntimeException('IMAP_MAILBOX must be set (e.g., "INBOX").');
        }
    }

    /** @return Generator<MessageReference> */
    public function fetch(ImapFetchFilter $filter): Generator
    {
        // Ensure this function is always a generator, even on early-return paths.
        if (false) { yield; }

        // Guard: establish connection
        $client = $this->factory->connect();
        if (!$client instanceof Client) {
            // Already a generator (see dummy yield above), so we can simply return
            return;
        }

        // Resolve mailbox (filter override > default)
        $mailbox = $filter->mailbox ?: $this->defaultMailbox;
        $folder  = $client->getFolder($mailbox);
        if ($folder === null) {
            return; // mailbox not found; return an empty generator
        }

        // Build query
        $query = $folder->query();

        // Date filter (guarded default)
        $since = $filter->since ?? new DateTimeImmutable('-7 days');
        $query->since($since);

        // Seen / Unseen
        if ($filter->onlyUnseen === true || ($filter->onlyUnseen === null && strcasecmp($this->defaultSearch, 'UNSEEN') === 0)) {
            $query->unseen();
        } elseif ($filter->onlyUnseen === false) {
            $query->seen();
        }

        $messages = $query->get();
        if (!is_iterable($messages)) {
            return; // nothing to yield
        }

        $emitted   = 0;
        $hardLimit = max(1, ($filter->limit ?? 50));

        /** @var Message $msg */
        foreach ($messages as $msg) {
            if ($emitted >= $hardLimit) {
                break;
            }

            // Extract core fields
            $subject = (string)($msg->getSubject() ?? '(no subject)');
            $from    = $this->fromResolver->resolve($msg);
            $mid     = $this->safeMessageId($msg);
            $date    = $this->safeDate($msg);
            $uid     = $this->safeUid($msg);

            // Optional client-side refinements
            if ($filter->fromContains && stripos($from, $filter->fromContains) === false) {
                continue;
            }
            if ($filter->subjectContains && stripos($subject, $filter->subjectContains) === false) {
                continue;
            }

            yield new MessageReference(
                vendorMessage: $msg,
                uid: $uid,
                subject: $subject,
                fromAddress: $from,
                messageId: $mid,
                receivedAt: $date,
                mailbox: $mailbox,
            );

            $emitted++;
        }
    }

    /** Return a safe UID (nullable). */
    private function safeUid(Message $msg): ?int
    {
        try {
            $uid = $msg->getUid();
            if (is_int($uid)) {
                return $uid;
            }
            if (is_string($uid) && ctype_digit($uid)) {
                return (int) $uid;
            }
        } catch (Throwable) {
            // ignore and return null
        }
        return null;
    }

    /** Return a safe message-id (nullable). */
    private function safeMessageId(Message $msg): ?string
    {
        try {
            $mid = $msg->getMessageId();
            return is_string($mid) && $mid !== '' ? $mid : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Return a safe received date (falls back to "now" if provider returns nothing). */
    private function safeDate(Message $msg): DateTimeImmutable
    {
        try {
            $raw = $msg->getDate();
            if ($raw instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($raw);
            }
            if (is_string($raw) && $raw !== '') {
                return new DateTimeImmutable($raw);
            }
        } catch (Throwable) {
            // fall through
        }
        return new DateTimeImmutable();
    }
}
