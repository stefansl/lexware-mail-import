<?php
declare(strict_types=1);

namespace App\Attachment\Provider;

use App\Attachment\Attachment;
use App\Attachment\AttachmentProviderInterface;
use App\Imap\MessageRef;

/**
 * Fallback provider: downloads attachments via PHP ext/imap by UID or by search.
 */
final class ExtImapAttachmentProvider implements AttachmentProviderInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $encryption,
        private readonly string $username,
        private readonly string $password,
    ) {}

    public function get(MessageRef $ref): iterable
    {
        if (!function_exists('imap_open')) return;

        $flags = '/imap';
        if ($this->encryption === 'ssl') $flags .= '/ssl';
        elseif ($this->encryption === 'tls') $flags .= '/tls';

        $mailbox = sprintf('{%s:%d%s}%s', $this->host, $this->port, $flags, $ref->mailbox);
        $imap = @imap_open($mailbox, $this->username, $this->password, OP_READONLY, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);
        if (!$imap) return;

        try {
            $uid = $ref->uid ?? $this->resolveUid($imap, $ref);
            if (!is_int($uid)) return;

            $struct = @imap_fetchstructure($imap, $uid, FT_UID);
            if (!$struct) return;

            yield from $this->collectParts($imap, $uid, $struct, '');
        } finally {
            try { if (is_object($imap) || is_resource($imap)) @imap_close($imap); } catch (\Throwable) {}
        }
    }

    /** @return iterable<Attachment> */
    private function collectParts($imap, int $uid, object $struct, string $partNo): iterable
    {
        if (isset($struct->parts) && is_array($struct->parts) && $struct->parts) {
            foreach ($struct->parts as $i => $sub) {
                $childNo = ($partNo === '') ? (string)($i + 1) : $partNo . '.' . ($i + 1);
                yield from $this->collectParts($imap, $uid, $sub, $childNo);
            }
            return;
        }

        [$isAttachment, $filename] = $this->isAttachment($struct);
        if (!$isAttachment) return;

        $section = ($partNo === '') ? '1' : $partNo;
        $body = @imap_fetchbody($imap, $uid, $section, FT_UID | FT_PEEK);
        if (!is_string($body) || $body === '') return;

        $encoding = isset($struct->encoding) ? (int)$struct->encoding : 0;
        if ($encoding === 3) $body = base64_decode($body, true) ?: '';
        elseif ($encoding === 4) $body = quoted_printable_decode($body);

        if ($body === '' || strlen($body) > 25 * 1024 * 1024) return;

        $mime = $this->guessMime($struct);
        yield new Attachment($this->sanitize($filename), $mime, $body);
    }

    private function isAttachment(object $struct): array
    {
        $isAttachment = false; $filename = null;

        if (isset($struct->disposition)) {
            $disp = strtoupper((string)$struct->disposition);
            if (in_array($disp, ['ATTACHMENT','INLINE'], true)) $isAttachment = true;
        }
        foreach (['dparameters','parameters'] as $prop) {
            if (isset($struct->$prop) && is_array($struct->$prop)) {
                foreach ($struct->$prop as $p) {
                    if (isset($p->attribute) && in_array(strtoupper($p->attribute), ['NAME','FILENAME'], true)) {
                        $filename = $p->value ?? $filename; $isAttachment = true;
                    }
                }
            }
        }
        return [$isAttachment, $filename];
    }

    private function guessMime(object $s): string
    {
        $primary = isset($s->type) ? (int)$s->type : 0;
        $sub = isset($s->subtype) ? strtolower((string)$s->subtype) : '';
        return match (true) {
            $primary === 3 && $sub !== '' => 'application/' . $sub,
            $primary === 0 && $sub !== '' => 'text/' . $sub,
            $sub !== ''                   => $sub,
            default                       => 'application/octet-stream',
        };
    }

    private function resolveUid($imap, MessageRef $ref): ?int
    {
        if ($ref->messageId) {
            $needle = str_replace('"','\"',$ref->messageId);
            $found = @imap_search($imap, 'HEADER Message-ID "'.$needle.'"', SE_UID);
            if (is_array($found) && $found) return (int)$found[0];
        }

        $subj = trim($ref->subject);
        if ($subj !== '') {
            $on = $ref->receivedAt->format('d-M-Y');
            $found = @imap_search($imap, sprintf('ON %s SUBJECT "%s"', $on, str_replace('"','\"',$subj)), SE_UID);
            if (is_array($found) && $found) return (int)$found[0];

            $since = date('d-M-Y', strtotime('-60 days'));
            $found = @imap_search($imap, sprintf('SINCE %s SUBJECT "%s"', $since, str_replace('"','\"',$subj)), SE_UID);
            if (is_array($found) && $found) return (int)$found[0];
        }

        if ($ref->fromAddress && $ref->fromAddress !== 'unknown@example.com') {
            $on = $ref->receivedAt->format('d-M-Y');
            $found = @imap_search($imap, sprintf('FROM "%s" ON %s', str_replace('"','\"',$ref->fromAddress), $on), SE_UID);
            if (is_array($found) && $found) return (int)$found[0];
        }

        return null;
    }

    private function sanitize(?string $name): ?string
    {
        if (!is_string($name) || $name === '') return null;
        $name = basename($name);
        $name = preg_replace('/[^\PC\s]/u', '', $name) ?? $name;
        return $name !== '' ? $name : null;
    }
}
