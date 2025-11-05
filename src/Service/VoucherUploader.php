<?php
declare(strict_types=1);

namespace App\Service;

use App\Entity\ImportedPdf;
use App\Service\Exception\UploadPreflightException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Handles preflight validation and upload of a single voucher PDF to Lexware.
 */
final class VoucherUploader
{
    public function __construct(
        private readonly LexwareClient $lexware,
        private readonly FileInspector $inspector,
        private readonly ErrorNotifier $notifier,
        #[Autowire(service: 'monolog.logger.lexware')]
        private readonly LoggerInterface $logger,
    ) {}

    /** Perform preflight + upload; update ImportedPdf state accordingly. */
    public function upload(ImportedPdf $pdf): void
    {
        $path = $pdf->getStoredPath();

        // Preflight check
        $meta = $this->inspector->validateVoucherUpload($path);
        $this->logger->info('upload preflight', [
            'path' => $path,
            'ok'   => $meta['ok'],
            'mime' => $meta['mime'] ?? null,
            'size' => $meta['size'] ?? 0,
        ]);
        if (!$meta['ok']) {
            $pdf->setSynced(false);
            $pdf->setLastError('preflight: '.$meta['reason']);
            return;
        }

        try {
            $res = $this->lexware->uploadVoucherFile($path);
            $pdf->setSynced(true);
            $pdf->setLexwareFileId($res['id'] ?? null);
            $pdf->setLexwareVoucherId($res['voucherId'] ?? null);
            $pdf->setLastError(null);
        } catch (UploadPreflightException $e) {
            // Already logged in client; mark and skip notification (validation error)
            $pdf->setSynced(false);
            $pdf->setLastError($e->getMessage());
        } catch (\Throwable $e) {
            $pdf->setSynced(false);
            $pdf->setLastError($e->getMessage());
            $this->notifier->notify(
                'Upload to Lexware API failed',
                sprintf("File: %s\nError: %s", $path, $e->getMessage())
            );
            $this->logger->error('Lexware upload failed', [
                'file'   => $path,
                'error'  => $e->getMessage(),
                'class'  => $e::class,
            ]);
        }
    }
}
