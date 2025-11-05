<?php
declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ImportMailsCommand;
use App\Contract\ImporterInterface;
use App\DTO\ImapFetchFilter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportMailsCommandTest extends TestCase
{
    public function testExecutePassesOptionsToImporter(): void
    {
        $importer = $this->createMock(ImporterInterface::class);

        $importer
            ->expects(self::once())
            ->method('runOnce')
            ->with(self::callback(function(ImapFetchFilter $filter) {
                // Assert mapped options
                return $filter->limit === 5
                    && $filter->fromContains === 'foo@bar'
                    && $filter->subjectContains === 'Invoice'
                    && $filter->mailbox === 'INBOX.Archive'
                    && $filter->onlyUnseen === false
                    && $filter->since instanceof \DateTimeImmutable
                    && $filter->since->format('Y-m-d') === '2025-01-01';
            }));

        $app = new Application();
        $app->add(new ImportMailsCommand($importer));
        $command = $app->find('app:import-mails');
        $tester = new CommandTester($command);

        $tester->execute([
            '--since' => '2025-01-01',
            '--limit' => '5',
            '--from' => 'foo@bar',
            '--subject-contains' => 'Invoice',
            '--mailbox' => 'INBOX.Archive',
            '--seen' => true,
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Done.', $tester->getDisplay());
    }

    public function testMutuallyExclusiveUnseenSeenWarns(): void
    {
        $importer = $this->createMock(ImporterInterface::class);
        $importer->expects(self::once())->method('runOnce');

        $app = new Application();
        $app->add(new ImportMailsCommand($importer));
        $command = $app->find('app:import-mails');
        $tester = new CommandTester($command);

        $tester->execute([
            '--unseen' => true,
            '--seen' => true,
        ]);

        self::assertStringContainsString('mutually exclusive', $tester->getDisplay());
    }
}
