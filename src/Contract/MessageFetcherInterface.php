<?php
declare(strict_types=1);

namespace App\Contract;

use App\DTO\ImapFetchFilter;
use App\Imap\MessageRef;

interface MessageFetcherInterface
{
    /**
     * @return \Generator|MessageRef[]
     */
    public function fetch(ImapFetchFilter $filter): \Generator;
}
