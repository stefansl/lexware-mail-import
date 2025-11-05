<?php
declare(strict_types=1);

namespace App\Service;

use App\Attachment\AttachmentChainProvider;
use App\Detection\PdfDetector;
use App\DTO\ImapFetchFilter;
use App\Entity\ImportedMail;
use App\Entity\ImportedPdf;
use App\Imap\WebklexMessageFetcher;
use App\Persistence\MailPersister;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Orchestrates: fetch -> attachments -> PDF filter -> persist -> upload -> flags & notify.
 * All comments are in English.
 */
final class Importer
{
    public function __construct(
        private readonly WebklexMessageFetcher $fetcher,
        private readonly AttachmentChainProvider $attachments,
        private readonly PdfDetector $pdfDetector,
        private readonly MailPersister $persister,
        private readonly VoucherUploader $uploader,
        private readonly LoggerInterface $logger,
    ) {}

    public function runOnce(?ImapFetchFilter $filter = null): void
    {
        $filter ??= new ImapFetchFilter();

        foreach ($this->fetcher->fetch($filter) as $ref) {
            // Persist mail row
            $mail = (new ImportedMail())
                ->setSubject($ref->subject)
                ->setFromAddress($ref->fromAddress)
                ->setMessageId($ref->messageId)
                ->setReceivedAt($ref->receivedAt);

            $this->persister->persistMail($mail);

            // Collect imported PDFs for upload phase
            $importedPdfs = [];

            // Extract & persist PDFs
            foreach ($this->attachments->get($ref) as $att) {
                if (!$this->pdfDetector->isPdf($att)) {
                    continue;
                }
                $pdf = $this->persister->persistPdf($mail, $att);
                $this->logger->info('attachment imported', [
                    'file' => $pdf->getStoredPath(),
                    'orig' => $pdf->getOriginalFilename(),
                ]);
                $importedPdfs[] = $pdf;
            }

            // Flush DB for mail + PDFs
            $this->persister->flush();

            // Upload each PDF (skip already-synced dedupes)
            /** @var ImportedPdf $pdf */
            foreach ($importedPdfs as $pdf) {
                if ($pdf->isSynced()) {
                    $this->logger->info('skip upload (already synced)', [
                        'file' => $pdf->getStoredPath(),
                        'hash' => $pdf->getFileHash(),
                    ]);
                    continue;
                }

                $this->uploader->upload($pdf);
            }

            // Flush DB for updated PDF flags/ids (single batch)
            $this->persister->flush();
        }
    }
}
