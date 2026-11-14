<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Installation\Infrastructure\Console;

use App\Installation\Application\SignInAttempts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The installation script's read of the sign-ins refused (scripts/onprem/twes): one line, `failed=<n> locked=<n>`, which
 * it compares with the thresholds in the shop's values. A moment that is no Unix time exits INVALID (2).
 */
#[AsCommand(name: 'app:installation:sign-ins', description: 'Count the sign-ins refused since a moment, and the accounts they locked (scripts/onprem/twes).')]
final class SignInsCommand extends Command
{
    public function __construct(private readonly SignInAttempts $attempts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'The moment counted from, in seconds since 1970 (UTC)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = $input->getOption('since');
        if (!\is_string($since) || 1 !== preg_match('/^\d{1,12}$/', $since)) {
            $output->writeln('<error>--since is a Unix time, seconds since 1970, such as 1792585800.</error>');

            return Command::INVALID;
        }
        $counts = $this->attempts->since(new \DateTimeImmutable('@'.$since));
        $output->writeln(\sprintf('failed=%d locked=%d', $counts->failed, $counts->locked));

        return Command::SUCCESS;
    }
}
