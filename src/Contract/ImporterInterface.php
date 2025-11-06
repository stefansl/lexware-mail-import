<?php
declare(strict_types=1);

namespace App\Contract;

use App\DTO\ImapFetchFilter;

/**
 * Runs one import cycle: fetch mails, persist records, extract PDFs, upload to Lexware, update flags, notify on error.
 */
interface ImporterInterface
{
    public function runOnce(?ImapFetchFilter $filter = null): void;
}
