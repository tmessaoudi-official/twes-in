<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataHealth\Infrastructure\Console;

use App\DataHealth\Application\CheckRun;
use App\DataHealth\Application\RunDataChecks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs every data check once and prints each one's time and findings, with the detail the operators are not told.
 * Fails when anything is found, so a script (the large-data run, a restore drill) stops on it.
 */
#[AsCommand(name: 'app:data:check', description: 'Check what the database holds against what no constraint states; fails on any finding.')]
final class CheckDataCommand extends Command
{
    public function __construct(private readonly RunDataChecks $checks)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('quietly', null, InputOption::VALUE_NONE, 'Print what is found without telling the operators');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $runs = true === $input->getOption('quietly') ? $this->checks->runQuietly() : $this->checks->handle();
        $io->table(['Check', 'Found', 'Seconds'], array_map(
            static fn (CheckRun $run): array => [$run->check, \count($run->findings), \sprintf('%.2f', $run->seconds)],
            $runs,
        ));
        $found = 0;
        foreach ($runs as $run) {
            foreach ($run->findings as $finding) {
                $io->writeln(\sprintf('%s  company %s  %s', $run->check, $finding->companyId?->toRfc4122() ?? '-', $finding->detail));
                ++$found;
            }
        }
        // What a run holds grows with the largest company's documents: watched here before the worker's limit is met.
        $io->writeln(\sprintf('Peak memory: %.1f MB', memory_get_peak_usage(true) / 1048576));
        if ($found > 0) {
            $io->error(\sprintf('Found: %d.', $found));

            return Command::FAILURE;
        }
        $io->success('Nothing found.');

        return Command::SUCCESS;
    }
}
