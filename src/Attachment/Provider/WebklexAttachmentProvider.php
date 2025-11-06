<?php
declare(strict_types=1);

namespace App\Attachment\Provider;

use App\Attachment\Attachment;
use App\Attachment\AttachmentProviderInterface;
use App\Imap\MessageReference;

/**
 * Primary provider: fetches attachments via Webklex and forces body load.
 */
final class WebklexAttachmentProvider implements AttachmentProviderInterface
{
    public function get(MessageReference $ref): iterable
    {
        $msg = $ref->vendorMessage;

        $lists = [];
        foreach ([
                     fn() => $msg->getAttachments(),
                     fn() => $msg->getAttachments(true),
                     fn() => $msg->getAttachments(true, true),
                     fn() => method_exists($msg, 'getInlineAttachments') ? $msg->getInlineAttachments() : [],
                 ] as $fn) {
            try { $lists[] = $this->toList($fn()); } catch (\Throwable) {}
        }

        foreach ($lists as $list) {
            foreach ($list as $att) {
                $this->forceLoad($att);
                [$filename, $mime, $bytes] = $this->normalize($att);
                if (!is_string($bytes) || $bytes === '') continue;

                // Guard large files (25 MB)
                if (strlen($bytes) > 25 * 1024 * 1024) continue;

                yield new Attachment($this->sanitize($filename), $mime, $bytes);
            }
        }
    }

    private function toList(mixed $container): array
    {
        if (is_array($container)) return $container;
        if ($container instanceof \Traversable) return iterator_to_array($container, false);
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

    private function forceLoad(object $att): void
    {
        foreach (['loadContent','fetch','parse'] as $m) {
            if (method_exists($att, $m)) { try { $att->$m(); } catch (\Throwable) {} }
        }
        if (method_exists($att,'getContent')) { try { $att->getContent(true); } catch (\Throwable) {} }
    }

    private function normalize(mixed $att): array
    {
        if (is_object($att)) {
            $filename = $att->getName() ?? ($att->name ?? null);
            $mime     = $att->getMimeType() ?? ($att->mime ?? ($att->getMime() ?? null));
            $content  = null;

            if (method_exists($att,'getContent')) { try { $content = $att->getContent(true); } catch (\Throwable) {} }
            if ($content === null && property_exists($att,'content')) { $content = $att->content; }
            if ((!is_string($content) || $content === '') && method_exists($att,'loadContent')) {
                try { $att->loadContent(); $content = $att->getContent(); } catch (\Throwable) {}
            }
            if (is_resource($content)) $content = stream_get_contents($content);

            return [$filename, is_string($mime) ? $mime : null, is_string($content) ? $content : null];
        }

        if (is_array($att)) {
            $filename = $att['name'] ?? $att['filename'] ?? null;
            $mime     = $att['mime'] ?? $att['mime_type'] ?? $att['content_type'] ?? null;
            $content  = $att['content'] ?? $att['data'] ?? null;
            if (is_resource($content)) $content = stream_get_contents($content);
            return [$filename, is_string($mime) ? $mime : null, is_string($content) ? $content : null];
        }

        return [null, null, null];
    }

    private function sanitize(?string $name): ?string
    {
        if (!is_string($name) || $name === '') return null;
        $name = basename($name);
        $name = preg_replace('/[^\PC\s]/u', '', $name) ?? $name;
        return $name !== '' ? $name : null;
    }
}
