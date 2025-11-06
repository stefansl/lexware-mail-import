<?php

declare(strict_types=1);

namespace App\Attachment\Provider;

use App\Attachment\Attachment;
use App\Attachment\AttachmentProviderInterface;
use App\Imap\MessageReference;

/**
 * Fallback provider: parses raw RFC822 if available, using php-mime-mail-parser.
 */
final class RawMimeAttachmentProvider implements AttachmentProviderInterface
{
    public function get(MessageReference $ref): iterable
    {
        $msg = $ref->vendorMessage;
        $raw = null;
        try {
            $raw = method_exists($msg, 'getRawMessage') ? $msg->getRawMessage()
                : (method_exists($msg, 'getRawBody') ? $msg->getRawBody() : $msg->getBody());
        } catch (\Throwable) {
            $raw = null;
        }

        if (!is_string($raw) || '' === $raw) {
            return;
        }

        $parser = $this->makeParser();
        if (!$parser) {
            return;
        }

        try {
            $parser->setText($raw);
        } catch (\Throwable) {
            return;
        }

        try {
            foreach ($parser->getAttachments() as $a) {
                try {
                    $name = method_exists($a, 'getFilename') ? $a->getFilename() : 'attachment.bin';
                    $mime = method_exists($a, 'getContentType') ? $a->getContentType() : null;
                    $data = method_exists($a, 'getContent') ? $a->getContent() : null;

                    if (!is_string($data) || '' === $data) {
                        continue;
                    }
                    if (strlen($data) > 25 * 1024 * 1024) {
                        continue;
                    }

                    yield new Attachment($this->sanitize($name), $mime, $data);
                } catch (\Throwable) {
                }
            }
        } catch (\Throwable) {
        }
    }

    private function makeParser(): ?object
    {
        if (class_exists(\PhpMimeMailParser\Parser::class)) {
            return new \PhpMimeMailParser\Parser();
        }
        if (class_exists(\eXorus\PhpMimeMailParser\Parser::class)) {
            return new \eXorus\PhpMimeMailParser\Parser();
        }

        return null;
    }

    private function sanitize(?string $name): ?string
    {
        if (!is_string($name) || '' === $name) {
            return null;
        }
        $name = basename($name);
        $name = preg_replace('/[^\PC\s]/u', '', $name) ?? $name;

        return '' !== $name ? $name : null;
    }
}
