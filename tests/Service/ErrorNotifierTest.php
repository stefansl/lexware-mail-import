<?php
declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\ErrorNotifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final class ErrorNotifierTest extends TestCase
{
    public function testNotifySendsEmailAndLogsInfo(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $mailer->expects(self::once())->method('send')->with(self::callback(function(Email $email) {
            return $email->getSubject() === 'Subject' && str_contains($email->getTextBody() ?? '', 'Message');
        }));
        $logger->expects(self::once())->method('info');

        $notifier = new ErrorNotifier($mailer, 'from@example.test', 'to@example.test', $logger);
        $notifier->notify('Subject', 'Message');
    }

    public function testNotifySwallowsMailerErrorsAndLogsError(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $mailer->method('send')->willThrowException(new \RuntimeException('smtp down'));
        $logger->expects(self::once())->method('error');

        $notifier = new ErrorNotifier($mailer, 'from@example.test', 'to@example.test', $logger);
        $notifier->notify('Oops', 'Something failed');

        self::assertTrue(true); // reached here without throwing
    }
}
