<?php

declare(strict_types=1);

namespace App\Contract;

use App\Attachment\Attachment;

interface PdfDetectorInterface
{
    public function isPdf(Attachment $a): bool;
}
