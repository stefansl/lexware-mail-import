<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;

/** Sends error notifications via email. */
final class ErrorNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromAddress,
        private readonly string $toAddress
    ) {}

    public function notify(string $subject, string $message): void
    {
        $email = (new Email())
            ->from($this->fromAddress)
            ->to($this->toAddress)
            ->subject($subject)
            ->text($message);

        try { $this->mailer->send($email); }
        catch (\Throwable $e) { error_log('[ErrorNotifier] send failed: '.$e->getMessage()); }
    }
}
