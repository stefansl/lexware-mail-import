<?php
declare(strict_types=1);

namespace App\Detection;

use App\Attachment\Attachment;

/** Decides if an attachment is a PDF (name/mime/magic header). */
final class PdfDetector
{
    public function isPdf(Attachment $a): bool
    {
        if ($a->filename && str_ends_with(strtolower($a->filename), '.pdf')) return true;
        if ($a->mime && stripos($a->mime, 'pdf') !== false) return true;
        return substr($a->bytes, 0, 5) === '%PDF-';
    }
}
