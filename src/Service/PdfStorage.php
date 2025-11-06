<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Stores PDF bytes in a year/month directory under a configured base path.
 */
final class PdfStorage
{
    public function __construct(private readonly string $storageDir)
    {
        if (!is_dir($this->storageDir)) {
            if (!@mkdir($this->storageDir, 0775, true) && !is_dir($this->storageDir)) {
                throw new \RuntimeException("Unable to create storage dir: {$this->storageDir}");
            }
        }
        if (!is_writable($this->storageDir)) {
            throw new \RuntimeException("PDF storage directory is not writable: {$this->storageDir}");
        }
    }

    public function store(string $bytes, string $originalName): string
    {
        $safe = $this->sanitizeFilename($originalName);
        $year = date('Y');
        $month = date('m');
        $dir = rtrim($this->storageDir, '/')."/{$year}/{$month}";
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create subdirectory: {$dir}");
        }

        $unique = bin2hex(random_bytes(6));
        $path = "{$dir}/{$unique}-{$safe}";
        if (false === file_put_contents($path, $bytes, LOCK_EX)) {
            throw new \RuntimeException("Failed to write file: {$path}");
        }

        return $path;
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename($name);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? $name;

        return substr($name, 0, 120) ?: 'attachment.pdf';
    }
}
