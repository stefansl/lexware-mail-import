<?php
declare(strict_types=1);

namespace App\Contract;

interface FileInspectorInterface
{
    /**
     * @return array{ok:bool, reason?:string, mime?:string, size:int}
     */
    public function validateVoucherUpload(string $path): array;
}
