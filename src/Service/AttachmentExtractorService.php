<?php

declare(strict_types=1);

namespace App\Service;

use Webklex\PHPIMAP\Message;

/**
 * Extracts attachments from IMAP messages using multiple fallback strategies.
 */
final readonly class AttachmentExtractorService
{
    public function __construct(
        private ImapConnectionService $connectionService,
    ) {
    }

    /**
     * Extract all PDF attachments from a message using multiple strategies.
     * Returns array of [filename, mime, bytes] tuples.
     *
     * @return array<array{string|null, string|null, string}>
     */
    public function extractPdfAttachments(Message $msg, string $mailbox): array
    {
        $pdfs = [];

        // Strategy 1: Webklex attachments
        $attachments = $this->extractViaWebklex($msg);
        foreach ($attachments as [$filename, $mime, $bytes]) {
            if ($this->isPdf($filename, $mime, $bytes)) {
                $pdfs[] = [$filename, $mime, $bytes];
            }
        }

        // Strategy 2: Raw MIME parser (fallback)
        if (empty($pdfs)) {
            $rawAttachments = $this->extractViaRawMime($msg);
            foreach ($rawAttachments as [$filename, $mime, $bytes]) {
                if ($this->isPdf($filename, $mime, $bytes)) {
                    $pdfs[] = [$filename, $mime, $bytes];
                }
            }
        }

        // Strategy 3: ext/imap fallback
        if (empty($pdfs)) {
            $imapAttachments = $this->extractViaExtImap($msg, $mailbox);
            foreach ($imapAttachments as [$filename, $mime, $bytes]) {
                if ($this->isPdf($filename, $mime, $bytes)) {
                    $pdfs[] = [$filename, $mime, $bytes];
                }
            }
        }

        return $pdfs;
    }

    private function extractViaWebklex(Message $msg): array
    {
        $candidates = [];

        // Try multiple attachment collection methods
        try {
            $candidates[] = $this->toList($msg->getAttachments());
        } catch (\Throwable) {
        }

        // Include inline attachments
        try {
            if (method_exists($msg, 'getInlineAttachments')) {
                $candidates[] = $this->toList($msg->getInlineAttachments());
            }
        } catch (\Throwable) {
        }

        $attachments = [];
        foreach ($candidates as $list) {
            foreach ($list as $att) {
                $this->forceLoadAttachmentBody($att);
                [$filename, $mime, $bytes] = $this->normalizeAttachment($att);
                if (null !== $bytes && '' !== $bytes) {
                    $attachments[] = [$filename, $mime, $bytes];
                }
            }
        }

        return $attachments;
    }

    private function extractViaRawMime(Message $msg): array
    {
        $raw = $this->getRawMessage($msg);
        if (!$raw) {
            return [];
        }

        $parser = $this->makeMimeParser();
        if (!$parser) {
            return [];
        }

        try {
            $parser->setText($raw);
        } catch (\Throwable) {
            return [];
        }

        $attachments = [];
        try {
            foreach ($parser->getAttachments() as $a) {
                try {
                    $name = method_exists($a, 'getFilename') ? $a->getFilename() : 'attachment.pdf';
                    $mime = method_exists($a, 'getContentType') ? $a->getContentType() : null;
                    $data = method_exists($a, 'getContent') ? $a->getContent() : null;
                    if (is_string($data) && '' !== $data) {
                        $attachments[] = [$this->sanitizeFilename($name), $mime, $data];
                    }
                } catch (\Throwable) {
                    // Skip broken attachment
                }
            }
        } catch (\Throwable) {
            // Skip if parser fails
        }

        return $attachments;
    }

    private function extractViaExtImap(Message $msg, string $mailbox): array
    {
        if (!function_exists('imap_open')) {
            return [];
        }

        $connectionParams = $this->connectionService->getConnectionParams();

        // Build mailbox string
        $flags = '/imap';
        if ('ssl' === $connectionParams['encryption']) {
            $flags .= '/ssl';
        } elseif ('tls' === $connectionParams['encryption']) {
            $flags .= '/tls';
        }

        $mailboxString = sprintf(
            '{%s:%d%s}%s',
            $connectionParams['host'],
            $connectionParams['port'],
            $flags,
            $mailbox
        );

        $imap = @imap_open($mailboxString, $connectionParams['username'], $connectionParams['password'], OP_READONLY, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);

        if (!$imap) {
            return [];
        }

        try {
            $uid = $this->resolveUid($imap, $msg);
            if (!$uid) {
                return [];
            }

            $structure = @imap_fetchstructure($imap, (int) $uid, FT_UID);
            if (!$structure) {
                return [];
            }

            $attachments = [];
            $this->collectImapParts($imap, (int) $uid, $structure, '', $attachments);

            return $attachments;
        } finally {
            $this->safeImapClose($imap);
        }
    }

    private function resolveUid($imap, Message $msg): ?int
    {
        // Try direct UID first
        try {
            if (method_exists($msg, 'getUid')) {
                $uid = $msg->getUid();
                if (is_int($uid) || is_string($uid)) {
                    return (int) $uid;
                }
            }
        } catch (\Throwable) {
        }

        // Try via Message-ID
        $messageId = $this->extractMessageId($msg);
        if ($messageId) {
            $needle = str_replace('"', '\"', $messageId);
            $found = @imap_search($imap, 'HEADER Message-ID "'.$needle.'"', SE_UID);
            if (is_array($found) && !empty($found)) {
                return (int) $found[0];
            }
        }

        // Additional fallback searches could be added here
        return null;
    }

    private function getRawMessage(Message $msg): ?string
    {
        try {
            if (method_exists($msg, 'getRawMessage')) {
                return $msg->getRawMessage();
            }
            if (method_exists($msg, 'getRawBody')) {
                return $msg->getRawBody();
            }

            return $msg->getBody();
        } catch (\Throwable) {
            return null;
        }
    }

    private function makeMimeParser(): ?object
    {
        if (class_exists(\PhpMimeMailParser\Parser::class)) {
            return new \PhpMimeMailParser\Parser();
        }
        if (class_exists(\eXorus\PhpMimeMailParser\Parser::class)) {
            return new \eXorus\PhpMimeMailParser\Parser();
        }

        return null;
    }

    private function normalizeAttachment(mixed $att): array
    {
        if (is_object($att)) {
            $filename = $this->callIfExists($att, ['getName', 'name']);
            $mime = $this->callIfExists($att, ['getMimeType', 'mime', 'getMime', 'getContentType']);

            $content = null;
            if (method_exists($att, 'getContent')) {
                try {
                    $content = $att->getContent(true);
                } catch (\Throwable) {
                }
            }

            if (null === $content) {
                $content = $this->callIfExists($att, ['content']);
            }

            if (is_resource($content)) {
                $content = stream_get_contents($content);
            }

            return [
                $this->sanitizeFilename($filename),
                is_string($mime) ? $mime : null,
                is_string($content) ? $content : null,
            ];
        }

        if (is_array($att)) {
            $filename = $att['name'] ?? $att['filename'] ?? null;
            $mime = $att['mime'] ?? $att['mime_type'] ?? $att['content_type'] ?? null;
            $content = $att['content'] ?? $att['data'] ?? null;

            if (is_resource($content)) {
                $content = stream_get_contents($content);
            }

            return [
                $this->sanitizeFilename($filename),
                is_string($mime) ? $mime : null,
                is_string($content) ? $content : null,
            ];
        }

        return [null, null, null];
    }

    private function isPdf(?string $filename, ?string $mime, string $bytes): bool
    {
        if ($filename && str_ends_with(strtolower($filename), '.pdf')) {
            return true;
        }
        if ($mime && false !== stripos($mime, 'pdf')) {
            return true;
        }

        return '%PDF-' === substr($bytes, 0, 5);
    }

    private function forceLoadAttachmentBody(object $att): void
    {
        foreach (['loadContent', 'fetch', 'parse'] as $method) {
            if (method_exists($att, $method)) {
                try {
                    $att->$method();
                } catch (\Throwable) {
                }
            }
        }
        if (method_exists($att, 'getContent')) {
            try {
                $att->getContent(true);
            } catch (\Throwable) {
            }
        }
    }

    private function toList(mixed $container): array
    {
        if (is_array($container)) {
            return $container;
        }
        if ($container instanceof \Traversable) {
            return iterator_to_array($container, false);
        }
        if (is_object($container)) {
            if (method_exists($container, 'all')) {
                $all = $container->all();

                return is_array($all) ? $all : ($all instanceof \Traversable ? iterator_to_array($all, false) : []);
            }
            if (method_exists($container, 'toArray')) {
                $arr = $container->toArray();

                return is_array($arr) ? $arr : [];
            }
        }

        return [];
    }

    private function callIfExists(object $obj, array $candidates): mixed
    {
        foreach ($candidates as $name) {
            if (method_exists($obj, $name)) {
                return $obj->$name();
            }
            if (property_exists($obj, $name)) {
                return $obj->$name;
            }
        }

        return null;
    }

    private function sanitizeFilename(?string $name): ?string
    {
        if (!is_string($name) || '' === $name) {
            return null;
        }
        $name = basename($name);
        $name = preg_replace('/[^\PC\s]/u', '', $name) ?? $name;

        return '' !== $name ? $name : null;
    }

    private function extractMessageId(Message $msg): ?string
    {
        try {
            $mid = $msg->getMessageId();

            return is_string($mid) && '' !== $mid ? $mid : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function collectImapParts($imap, int $uid, object $struct, string $partNo, array &$out): void
    {
        // Multipart: recurse into children
        if (isset($struct->parts) && is_array($struct->parts) && count($struct->parts) > 0) {
            foreach ($struct->parts as $i => $sub) {
                $childNo = ('' === $partNo) ? (string) ($i + 1) : ($partNo.'.'.($i + 1));
                $this->collectImapParts($imap, $uid, $sub, $childNo, $out);
            }

            return;
        }

        // Check if this is an attachment
        $isAttachment = false;
        $filename = null;

        if (isset($struct->disposition)) {
            $disp = strtoupper((string) $struct->disposition);
            if (in_array($disp, ['ATTACHMENT', 'INLINE'], true)) {
                $isAttachment = true;
            }
        }

        foreach (['dparameters', 'parameters'] as $prop) {
            if (isset($struct->$prop) && is_array($struct->$prop)) {
                foreach ($struct->$prop as $p) {
                    if (isset($p->attribute) && in_array(strtoupper($p->attribute), ['NAME', 'FILENAME'], true)) {
                        $filename = $p->value ?? $filename;
                        $isAttachment = true;
                    }
                }
            }
        }

        if (!$isAttachment) {
            return;
        }

        $section = ('' === $partNo) ? '1' : $partNo;
        $body = @imap_fetchbody($imap, $uid, $section, FT_UID | FT_PEEK);

        if (false === $body || '' === $body) {
            return;
        }

        // Decode transfer encoding
        $encoding = isset($struct->encoding) ? (int) $struct->encoding : 0;
        switch ($encoding) {
            case 3: // base64
                $decoded = base64_decode($body, true);
                $body = (false !== $decoded) ? $decoded : '';
                break;
            case 4: // quoted-printable
                $body = quoted_printable_decode($body);
                break;
        }

        // Build MIME type
        $primary = isset($struct->type) ? (int) $struct->type : 0;
        $subtype = isset($struct->subtype) ? strtolower((string) $struct->subtype) : '';
        $mime = 'application/octet-stream';
        if (3 === $primary && '' !== $subtype) {
            $mime = 'application/'.$subtype;
        } elseif (0 === $primary && '' !== $subtype) {
            $mime = 'text/'.$subtype;
        } elseif ('' !== $subtype) {
            $mime = $subtype;
        }

        $out[] = [
            $this->sanitizeFilename($filename) ?? 'attachment.bin',
            $mime,
            is_string($body) ? $body : '',
        ];
    }

    private function safeImapClose($imap): void
    {
        try {
            if (is_object($imap) || (is_resource($imap) && 'imap' === get_resource_type($imap))) {
                @imap_close($imap);
            }
        } catch (\Throwable) {
            // Swallow; connection may already be closed
        }
    }
}
