<?php

declare(strict_types=1);

namespace App\Detection;

use App\Attachment\Attachment;
use App\Contract\PdfDetectorInterface;

/** Decides if an attachment is a PDF (name/mime/magic header). */
final class PdfDetector implements PdfDetectorInterface
{
    public function isPdf(Attachment $a): bool
    {
        if ($a->filename && str_ends_with(strtolower($a->filename), '.pdf')) {
            return true;
        }
        if ($a->mime && false !== stripos($a->mime, 'pdf')) {
            return true;
        }

        return '%PDF-' === substr($a->bytes, 0, 5);
    }
}
