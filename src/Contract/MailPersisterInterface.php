<?php

declare(strict_types=1);

namespace App\Contract;

use App\Attachment\Attachment;
use App\Entity\ImportedMail;
use App\Entity\ImportedPdf;

interface MailPersisterInterface
{
    public function persistMail(ImportedMail $mail): ImportedMail;

    public function persistPdf(ImportedMail $mail, Attachment $att): ImportedPdf;

    public function flush(): void;
}
