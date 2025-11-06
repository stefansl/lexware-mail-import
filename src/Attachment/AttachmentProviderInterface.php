<?php

declare(strict_types=1);

namespace App\Attachment;

use App\Imap\MessageReference;

interface AttachmentProviderInterface
{
    /** @return iterable<Attachment> */
    public function get(MessageReference $ref): iterable;
}
