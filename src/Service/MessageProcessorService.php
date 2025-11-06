<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ImportedPdf;
use App\Entity\Mail;
use Webklex\PHPIMAP\Message;

final class MessageProcessorService
{
    public function __construct(
        private readonly AttachmentExtractorService $attachmentExtractor,
        // andere Abhängigkeiten, die du benötigst
    ) {
    }

    /**
     * Process a single IMAP message and return [Mail, ImportedPdf[]].
     *
     * @return array{0: Mail, 1: ImportedPdf[]}
     */
    public function processMessage(Message $msg, string $mailbox): array
    {
        // Implementierung der Nachrichtenverarbeitung
        // - Erstelle Mail-Entity
        // - Extrahiere Anhänge/PDFs
        // - Speichere ImportedPdf-Entities

        $mail = new Mail(); // oder hol bestehende
        $importedPdfs = [];

        // ... deine Logik hier ...

        return [$mail, $importedPdfs];
    }
}
