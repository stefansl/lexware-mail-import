<?php
declare(strict_types=1);

namespace App\Persistence;

use App\Attachment\Attachment;
use App\Contract\MailPersisterInterface;
use App\Entity\ImportedMail;
use App\Entity\ImportedPdf;
use App\Service\PdfStorage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Persists ImportedMail and ImportedPdf rows; stores file bytes via PdfStorage.
 */
final class MailPersister implements MailPersisterInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PdfStorage $storage,
    ) {}

    public function persistMail(ImportedMail $mail): ImportedMail
    {
        $this->em->persist($mail);
        return $mail;
    }

    public function persistPdf(ImportedMail $mail, Attachment $att): ImportedPdf
    {
        $bytes = $att->bytes;
        $hash  = hash('sha256', $bytes);

        $existing = $this->em->getRepository(ImportedPdf::class)->findOneBy(['fileHash' => $hash]);
        if ($existing) {
            return $existing; // idempotent: duplicate file skipped
        }

        $storedPath = $this->storage->store($bytes, $att->filename ?? 'attachment.pdf');

        $pdf = new ImportedPdf();
        $pdf->setMail($mail);
        $pdf->setOriginalFilename($att->filename ?? 'attachment.pdf');
        $pdf->setStoredPath($storedPath);
        $pdf->setImportedAt(new \DateTimeImmutable());
        $pdf->setSynced(false);
        $pdf->setMime($att->mime);
        $pdf->setSize(strlen($bytes));
        $pdf->setFileHash($hash);

        $this->em->persist($pdf);
        return $pdf;
    }

    public function flush(): void
    {
        $this->em->flush();
    }
}
