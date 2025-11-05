<?php
declare(strict_types=1);

namespace App\Command;

use App\Contract\ImporterInterface;
use App\DTO\ImapFetchFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Console command that triggers the mail import pipeline.
 * All comments in English.
 */
#[AsCommand(
    name: 'app:import-mails',
    description: 'Fetch IMAP messages, extract PDFs, persist and sync to Lexware.'
)]
final class ImportMailsCommand extends Command
{
    public function __construct(
        private readonly ImporterInterface $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Lower bound for message date (YYYY-MM-DD).')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max number of messages to process.', '50')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Filter: substring in From address.')
            ->addOption('subject-contains', null, InputOption::VALUE_REQUIRED, 'Filter: substring in Subject.')
            ->addOption('mailbox', null, InputOption::VALUE_REQUIRED, 'Folder name, e.g. INBOX.')
            ->addOption('unseen', null, InputOption::VALUE_NONE, 'Only unseen messages.')
            ->addOption('seen', null, InputOption::VALUE_NONE, 'Only seen messages.');
    }

    protected function execute(InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $sinceOpt = $input->getOption('since');
        $since = null;
        if (is_string($sinceOpt) && $sinceOpt !== '') {
            try { $since = new \DateTimeImmutable($sinceOpt); } catch (\Throwable) { $since = null; }
        }

        $unseen = (bool)$input->getOption('unseen');
        $seen   = (bool)$input->getOption('seen');
        $onlyUnseen = $unseen ? true : ($seen ? false : null);
        if ($unseen && $seen) {
            $io->warning('Options --unseen and --seen are mutually exclusive. Using --unseen.');
        }

        $limit = max(1, (int)$input->getOption('limit'));

        $filter = new ImapFetchFilter(
            since: $since,
            limit: $limit,
            fromContains: (string)($input->getOption('from') ?? ''),
            subjectContains: (string)($input->getOption('subject-contains') ?? ''),
            mailbox: (string)($input->getOption('mailbox') ?? ''),
            onlyUnseen: $onlyUnseen
        );

        $this->importer->runOnce($filter);

        $io->success('Done.');
        return Command::SUCCESS;
    }
}
