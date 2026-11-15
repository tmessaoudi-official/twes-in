<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Console;

use App\Identity\Application\Account\ManageAccounts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A suspected breach (docs/RUNBOOK.md « A suspected breach »): every session of every account ends, a session an
 * attacker took among them, and each account is told by mail and signs in again. Whoever can run this already holds the
 * server; it asks first all the same, as everyone at a till is signed out by it.
 */
#[AsCommand(name: 'app:sessions:end-all', description: 'End every session of every account, after a suspected breach; each account is told by mail.')]
final class EndEverySessionCommand extends Command
{
    public function __construct(private readonly ManageAccounts $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'End them without asking, for a script');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (true !== $input->getOption('force')) {
            if (!$input->isInteractive()) {
                $io->error('This signs everyone out: run it from a terminal, or add --force to a script.');

                return Command::INVALID;
            }
            if (!$io->confirm('End every session of every account now? Everyone signs in again, each account is told by mail.', false)) {
                $io->warning('Nothing was changed.');

                return Command::FAILURE;
            }
        }

        $ended = $this->accounts->endEverySession();
        $io->success(\sprintf('Ended the sessions of %d account%s; each was told by mail and signs in again.', $ended, 1 === $ended ? '' : 's'));

        return Command::SUCCESS;
    }
}
