<?php

declare(strict_types=1);

namespace App\Service;

use App\Attachment\AttachmentChainProvider;
use App\Contract\ImporterInterface;
use App\Detection\PdfDetector;
use App\DTO\ImapFetchFilter;
use App\Entity\ImportedMail;
use App\Entity\ImportedPdf;
use App\Imap\WebklexMessageFetcher;
use App\Persistence\MailPersister;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Orchestrates the full pipeline:
 *  - fetch messages
 *  - persist mail rows
 *  - extract attachments, filter PDFs, persist PDFs
 *  - upload PDFs to Lexware, update flags/ids, notify on error
 *
 * Notes:
 *  - Logs a NOTICE for every imported mail into the "importer" channel.
 *  - Uses batch flush: once after persisting mail+pdfs, once after uploads.
 *  - Skips upload for already-synced PDFs (deduplicated rows).
 */
final class Importer implements ImporterInterface
{
    public function __construct(
        private readonly WebklexMessageFetcher $fetcher,
        private readonly AttachmentChainProvider $attachments,
        private readonly PdfDetector $pdfDetector,
        private readonly MailPersister $persister,
        private readonly LexwareClient $lexware,
        private readonly ErrorNotifier $notifier,
        #[Autowire(service: 'monolog.logger.importer')]
        private readonly LoggerInterface $logger,
    ) {
    }

    public function runOnce(?ImapFetchFilter $filter = null): void
    {
        $filter ??= new ImapFetchFilter();

        foreach ($this->fetcher->fetch($filter) as $ref) {
            // Persist the mail record
            $mail = (new ImportedMail())
                ->setSubject($ref->subject)
                ->setFromAddress($ref->fromAddress)
                ->setMessageId($ref->messageId)
                ->setReceivedAt($ref->receivedAt);

            $this->persister->persistMail($mail);

            // Log at NOTICE level so it appears in var/log/importer.log
            $this->logger->notice('mail imported', [
                'subject' => $mail->getSubject(),
                'from' => $mail->getFromAddress(),
                'message_id' => $mail->getMessageId(),
                'received' => $mail->getReceivedAt()?->format(DATE_ATOM),
                'mailbox' => $ref->mailbox ?? null,
            ]);

            // Collect imported PDFs for subsequent upload
            $importedPdfs = [];

            // Extract attachments and persist PDFs only
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

            // Flush persisted mail and PDFs
            $this->persister->flush();

            // Upload each PDF; skip if already synced (deduplicated record)
            /** @var ImportedPdf $pdf */
            foreach ($importedPdfs as $pdf) {
                if ($pdf->isSynced()) {
                    $this->logger->info('skip upload (already synced)', [
                        'file' => $pdf->getStoredPath(),
                        'hash' => $pdf->getFileHash(),
                    ]);
                    continue;
                }

                try {
                    $res = $this->lexware->uploadVoucherFile($pdf->getStoredPath());
                    $pdf->setSynced(true);
                    $pdf->setLexwareFileId($res['id'] ?? null);
                    $pdf->setLexwareVoucherId($res['voucherId'] ?? null);
                    $pdf->setLastError(null);
                    $this->logger->info('upload ok', [
                        'file' => $pdf->getStoredPath(),
                        'lexware_file' => $pdf->getLexwareFileId(),
                        'voucher_id' => $pdf->getLexwareVoucherId(),
                    ]);
                } catch (\Throwable $e) {
                    // Mark as failed and notify
                    $pdf->setSynced(false);
                    $pdf->setLastError($e->getMessage());
                    $this->notifier->notify(
                        'Upload to Lexware API failed',
                        sprintf("File: %s\nError: %s", $pdf->getStoredPath(), $e->getMessage())
                    );
                    $this->logger->error('upload failed', [
                        'file' => $pdf->getStoredPath(),
                        'error' => $e->getMessage(),
                        'class' => $e::class,
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }

            // Flush updated PDF flags and ids in a single batch
            $this->persister->flush();
        }
    }
}
