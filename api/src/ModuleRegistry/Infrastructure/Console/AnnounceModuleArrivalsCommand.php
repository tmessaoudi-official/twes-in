<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\Console;

use App\ModuleRegistry\Application\ModuleInterests;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tells the companies that asked « Me prévenir » that their module has arrived (docs/SPEC.md § 7, 2026-09-26). A
 * module arrives with a release, so `infra/api/docker-entrypoint.sh` runs this after the migrations at every start;
 * each wait is told once, so running it again tells nobody twice.
 */
#[AsCommand(name: 'app:modules:announce-arrivals', description: 'Tell the companies waiting for a module that now ships (idempotent).')]
final class AnnounceModuleArrivalsCommand extends Command
{
    public function __construct(private readonly ModuleInterests $interests)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $told = $this->interests->announceArrivals();
        if ($told > 0) {
            $output->writeln(\sprintf('Told %d waiting compan%s that a module arrived.', $told, 1 === $told ? 'y' : 'ies'));
        }

        return Command::SUCCESS;
    }
}
