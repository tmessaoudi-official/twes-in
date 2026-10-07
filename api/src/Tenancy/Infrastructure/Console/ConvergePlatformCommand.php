<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Console;

use App\Tenancy\Application\Seed\SeedPlatform;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Brings a database an earlier release wrote up to this one: the built-in roles' permissions, the presets' customer
 * tax regimes, each company's copy of its preset (a new kind of document's numbering series among them). What exists is
 * never touched beyond that, and no operator or company is created, so `infra/api/docker-entrypoint.sh` runs it after
 * the migrations at every start.
 */
#[AsCommand(name: 'app:platform:converge', description: 'Bring the built-in roles and every company up to this release (idempotent).')]
final class ConvergePlatformCommand extends Command
{
    public function __construct(private readonly SeedPlatform $seed)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $changed = $this->seed->converge();
        if ([] !== $changed) {
            $output->writeln('Converged: '.implode(', ', $changed).'.');
        }

        return Command::SUCCESS;
    }
}
