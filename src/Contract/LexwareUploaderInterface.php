<?php
declare(strict_types=1);

namespace App\Contract;

interface LexwareUploaderInterface
{
    /**
     * Upload a voucher file and return decoded JSON array (may be empty).
     *
     * @return array<string,mixed>
     */
    public function uploadVoucherFile(string $absolutePath): array;
}
