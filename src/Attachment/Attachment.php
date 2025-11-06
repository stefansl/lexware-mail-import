<?php

declare(strict_types=1);

namespace App\Attachment;

/** Value object for attachment content (raw bytes, not base64). */
final class Attachment
{
    public function __construct(
        public readonly ?string $filename,
        public readonly ?string $mime,
        public readonly string $bytes,
    ) {
    }
}
