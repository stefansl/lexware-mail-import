<?php
declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;

/** Sends error notifications via email. */
final class ErrorNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $fromAddress,
        private readonly string $toAddress,
        #[Autowire(service: 'monolog.logger.importer')]
        private readonly LoggerInterface $logger,
    ) {}

    public function notify(string $subject, string $message): void
    {
        $email = (new Email())
            ->from($this->fromAddress)
            ->to($this->toAddress)
            ->subject($subject)
            ->text($message);

        try {
            $this->mailer->send($email);
            $this->logger->info('Error notification sent', ['to' => $this->toAddress, 'subject' => $subject]);
        } catch (\Throwable $e) {
            $this->logger->error('ErrorNotifier send failed', [
                'error' => $e->getMessage(),
                'subject' => $subject,
            ]);
        }
    }
}
