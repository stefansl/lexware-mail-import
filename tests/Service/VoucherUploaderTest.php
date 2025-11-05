<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Contract\ErrorNotifierInterface;
use App\Contract\FileInspectorInterface;
use App\Contract\LexwareUploaderInterface;
use App\Service\VoucherUploader;
use App\Entity\ImportedPdf;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class VoucherUploaderTest extends TestCase
{
    private function tempPdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vu_');
        if ($path === false) self::fail('tempnam failed');
        file_put_contents($path, "%PDF-1.4\ncontent");
        return $path;
    }

    private function makePdf(string $path): ImportedPdf
    {
        $pdf = new ImportedPdf();
        $ref = new \ReflectionProperty(ImportedPdf::class, 'storedPath');
        $ref->setAccessible(true);
        $ref->setValue($pdf, $path);
        return $pdf;
    }

    public function testSuccessMarksSyncedAndSetsIds(): void
    {
        $file = $this->tempPdf();
        try {
            $client = $this->createMock(LexwareUploaderInterface::class);
            $inspector = $this->createMock(FileInspectorInterface::class);
            $notifier = $this->createMock(ErrorNotifierInterface::class);
            $logger = $this->createMock(LoggerInterface::class);

            $inspector->method('validateVoucherUpload')->willReturn(['ok' => true, 'mime' => 'application/pdf', 'size' => 10]);
            $client->method('uploadVoucherFile')->willReturn(['id' => 'F1', 'voucherId' => 'V1']);

            $uploader = new VoucherUploader($client, $inspector, $notifier, $logger);
            $pdf = $this->makePdf($file);

            $uploader->upload($pdf);

            self::assertTrue($pdf->isSynced());
            self::assertSame('F1', $pdf->getLexwareFileId());
            self::assertSame('V1', $pdf->getLexwareVoucherId());
            self::assertNull($pdf->getLastError());
        } finally { @unlink($file); }
    }

    public function testPreflightMetaFailSetsErrorAndSkipsClient(): void
    {
        $file = $this->tempPdf();
        try {
            $client = $this->createMock(LexwareUploaderInterface::class);
            $inspector = $this->createMock(FileInspectorInterface::class);
            $notifier = $this->createMock(ErrorNotifierInterface::class);
            $logger = $this->createMock(LoggerInterface::class);

            $inspector->method('validateVoucherUpload')->willReturn(['ok' => false, 'reason' => 'file_too_large', 'size' => 999]);
            $client->expects(self::never())->method('uploadVoucherFile');

            $uploader = new VoucherUploader($client, $inspector, $notifier, $logger);
            $pdf = $this->makePdf($file);

            $uploader->upload($pdf);

            self::assertFalse($pdf->isSynced());
            self::assertNotNull($pdf->getLastError());
            self::assertStringContainsString('preflight', (string)$pdf->getLastError());
        } finally { @unlink($file); }
    }

    public function testUploadPreflightExceptionSetsErrorNoNotify(): void
    {
        $file = $this->tempPdf();
        try {
            $client = $this->createMock(LexwareUploaderInterface::class);
            $inspector = $this->createMock(FileInspectorInterface::class);
            $notifier = $this->createMock(ErrorNotifierInterface::class);
            $logger = $this->createMock(LoggerInterface::class);

            $inspector->method('validateVoucherUpload')->willReturn(['ok' => true, 'mime' => 'application/pdf', 'size' => 10]);
            $client->method('uploadVoucherFile')->willThrowException(new \App\Service\Exception\UploadPreflightException('bad mime'));
            $notifier->expects(self::never())->method('notify');

            $uploader = new VoucherUploader($client, $inspector, $notifier, $logger);
            $pdf = $this->makePdf($file);

            $uploader->upload($pdf);

            self::assertFalse($pdf->isSynced());
            self::assertSame('bad mime', $pdf->getLastError());
        } finally { @unlink($file); }
    }

    public function testGenericExceptionTriggersNotifier(): void
    {
        $file = $this->tempPdf();
        try {
            $client = $this->createMock(LexwareUploaderInterface::class);
            $inspector = $this->createMock(FileInspectorInterface::class);
            $notifier = $this->createMock(ErrorNotifierInterface::class);
            $logger = $this->createMock(LoggerInterface::class);

            $inspector->method('validateVoucherUpload')->willReturn(['ok' => true, 'mime' => 'application/pdf', 'size' => 10]);
            $client->method('uploadVoucherFile')->willThrowException(new \RuntimeException('boom'));
            $notifier->expects(self::once())->method('notify');

            $uploader = new VoucherUploader($client, $inspector, $notifier, $logger);
            $pdf = $this->makePdf($file);

            $uploader->upload($pdf);

            self::assertFalse($pdf->isSynced());
            self::assertSame('boom', $pdf->getLastError());
        } finally { @unlink($file); }
    }
}
