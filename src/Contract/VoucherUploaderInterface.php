<?php
declare(strict_types=1);

namespace App\Contract;

use App\Entity\ImportedPdf;

interface VoucherUploaderInterface
{
    public function upload(ImportedPdf $pdf): void;
}
