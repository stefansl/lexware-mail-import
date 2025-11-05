<?php
declare(strict_types=1);

namespace App\Contract;

use App\DTO\ImapFetchFilter;

interface ImporterInterface
{
    public function runOnce(?ImapFetchFilter $filter = null): void;
}
