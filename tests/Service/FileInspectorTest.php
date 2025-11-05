<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\FileInspector;
use PHPUnit\Framework\TestCase;

final class FileInspectorTest extends TestCase
{
    private function makeTempFile(string $content = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fi_');
        if ($path === false) {
            self::fail('tempnam failed');
        }
        file_put_contents($path, $content);
        return $path;
    }

    public function testFileNotFound(): void
    {
        $inspector = new FileInspector(1024, ['application/pdf']);
        $res = $inspector->validateVoucherUpload('/non/existent/file.pdf');
        self::assertFalse($res['ok']);
        self::assertSame('file_not_found', $res['reason']);
        self::assertSame(0, $res['size']);
    }

    public function testEmptyFile(): void
    {
        $inspector = new FileInspector(1024, ['application/pdf']);
        $path = $this->makeTempFile('');
        try {
            $res = $inspector->validateVoucherUpload($path);
            self::assertFalse($res['ok']);
            self::assertSame('empty_file', $res['reason']);
        } finally {
            @unlink($path);
        }
    }

    public function testTooLarge(): void
    {
        $inspector = new FileInspector(3, ['application/pdf']);
        $path = $this->makeTempFile("%PDF-abc");
        try {
            $res = $inspector->validateVoucherUpload($path);
            self::assertFalse($res['ok']);
            self::assertSame('file_too_large', $res['reason']);
            self::assertGreaterThan(3, $res['size']);
        } finally {
            @unlink($path);
        }
    }

    public function testUnsupportedMime(): void
    {
        $inspector = new FileInspector(1024, ['application/pdf']);
        // Create a PNG file signature without listing image/png in allowed
        $path = $this->makeTempFile("\x89PNG\r\n\x1a\n");
        try {
            $res = $inspector->validateVoucherUpload($path);
            self::assertFalse($res['ok']);
            self::assertStringStartsWith('unsupported_mime_', (string)($res['reason'] ?? ''));
        } finally {
            @unlink($path);
        }
    }

    public function testValidPdf(): void
    {
        $inspector = new FileInspector(1024 * 1024, ['application/pdf']);
        $path = $this->makeTempFile("%PDF-1.4\nrest");
        try {
            $res = $inspector->validateVoucherUpload($path);
            self::assertTrue($res['ok']);
            self::assertSame('application/pdf', $res['mime']);
            self::assertGreaterThan(0, $res['size']);
        } finally {
            @unlink($path);
        }
    }
}
