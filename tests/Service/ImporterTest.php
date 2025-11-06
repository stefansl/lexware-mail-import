<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Attachment\Attachment;
use App\Attachment\AttachmentProviderInterface;
use App\Contract\ImporterInterface;
use App\Contract\MailPersisterInterface;
use App\Contract\MessageFetcherInterface;
use App\Contract\PdfDetectorInterface;
use App\Contract\VoucherUploaderInterface;
use App\DTO\ImapFetchFilter;
use App\Entity\ImportedMail;
use App\Entity\ImportedPdf;
use App\Imap\MessageReference;
use App\Service\Importer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ImporterTest extends TestCase
{
    private function messageRef(): MessageReference
    {
        return new MessageReference(vendorMessage: (object)[], uid: 1, subject: 'Subj', fromAddress: 'a@b.c', messageId: 'm1', receivedAt: new \DateTimeImmutable('2025-01-01'), mailbox: 'INBOX');
    }

    private function makePdfEntity(string $path, bool $synced): ImportedPdf
    {
        $pdf = new ImportedPdf();
        // Set required fields via reflection since there is no constructor
        (function() use ($path, $synced) {
            $this->storedPath = $path;
            $this->synced = $synced;
            $this->originalFilename = basename($path);
            $this->importedAt = new \DateTimeImmutable();
        })->call($pdf);
        return $pdf;
    }

    public function testImporterProcessesPdfsAndSkipsAlreadySynced(): void
    {
        $fetcher = $this->createMock(MessageFetcherInterface::class);
        $attachments = $this->createMock(AttachmentProviderInterface::class);
        $detector = $this->createMock(PdfDetectorInterface::class);
        $persister = $this->createMock(MailPersisterInterface::class);
        $uploader = $this->createMock(VoucherUploaderInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $ref = $this->messageRef();
        $fetcher->method('fetch')->willReturn((function() use ($ref) { yield $ref; })());

        $att1 = new Attachment('a1.pdf', 'application/pdf', "%PDF-...");
        $att2 = new Attachment('a2.pdf', 'application/pdf', "%PDF-...");
        $attachments->method('get')->with($ref)->willReturn([$att1, $att2]);

        $detector->method('isPdf')->willReturn(true);

        $mail = (new ImportedMail())
            ->setSubject($ref->subject)
            ->setFromAddress($ref->fromAddress)
            ->setMessageId($ref->messageId)
            ->setReceivedAt($ref->receivedAt);
        $persister->method('persistMail')->willReturn($mail);

        $pdf1 = $this->makePdfEntity('/tmp/a1.pdf', false);
        $pdf2 = $this->makePdfEntity('/tmp/a2.pdf', true); // already synced → should skip
        $persister->method('persistPdf')->willReturnOnConsecutiveCalls($pdf1, $pdf2);

        // Expect first uploaded, second skipped
        $uploader->expects(self::once())->method('upload')->with($pdf1);

        // Expect flush twice (once after persisting, once after uploads)
        $persister->expects(self::exactly(2))->method('flush');

        $svc = new Importer($fetcher, $attachments, $detector, $persister, $uploader, $logger);
        $svc->runOnce(new ImapFetchFilter(limit: 10));

        self::assertTrue(true);
    }
}
