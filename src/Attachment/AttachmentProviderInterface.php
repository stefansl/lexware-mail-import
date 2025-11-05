<?php
declare(strict_types=1);

namespace App\Attachment;

use App\Imap\MessageRef;

interface AttachmentProviderInterface
{
    /** @return iterable<Attachment> */
    public function get(MessageRef $ref): iterable;
}
