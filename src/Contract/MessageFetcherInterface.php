<?php
declare(strict_types=1);

namespace App\Contract;

use App\DTO\ImapFetchFilter;
use App\Imap\MessageReference;

interface MessageFetcherInterface
{
    /**
     * @return \Generator|MessageReference[]
     */
    public function fetch(ImapFetchFilter $filter): \Generator;
}
