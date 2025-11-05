<?php

declare(strict_types=1);

namespace App\Service;

final readonly class FileInspector
{
    public function __construct(
        private int $maxBytes,
        /** @var list<string> */ private array $allowedMimes,
    ) {
    }

    /** @return array{ok:bool, reason?:string, mime?:string, size:int} */
    public function validateVoucherUpload(string $path): array
    {
        if (!is_file($path)) {
            return ['ok' => false, 'reason' => 'file_not_found', 'size' => 0];
        }

        $size = filesize($path) ?: 0;
        if ($size === 0) {
            return ['ok' => false, 'reason' => 'empty_file', 'size' => 0];
        }
        if ($size > $this->maxBytes) {
            return ['ok' => false, 'reason' => 'file_too_large', 'size' => $size];
        }

        $fi = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $fi->file($path) ?: 'application/octet-stream';

        $ok = in_array($mime, $this->allowedMimes, true) && (
                $mime !== 'application/pdf' || $this->looksLikePdf($path)
            );

        if (!$ok) {
            return ['ok' => false, 'reason' => 'unsupported_mime_' . $mime, 'mime' => $mime, 'size' => $size];
        }

        return ['ok' => true, 'mime' => $mime, 'size' => $size];
    }

    private function looksLikePdf(string $path): bool
    {
        $h = @fopen($path, 'rb');
        if (!$h) {
            return false;
        }
        $head = fread($h, 5) ?: '';
        fclose($h);
        return $head === '%PDF-';
    }
}
