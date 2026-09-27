<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\Console;

use App\Legal\Application\SeedLegalDrafts;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes the shipped legal drafts where a page has no version yet in their language (docs/SPEC.md § 8 row 148).
 * `infra/api/docker-entrypoint.sh` runs it after the migrations at every start; it never touches a page that has a
 * version, so running it again writes nothing.
 */
#[AsCommand(name: 'app:legal:seed-drafts', description: 'Write the shipped legal drafts where a page has no version yet (idempotent).')]
final class SeedLegalDraftsCommand extends Command
{
    public function __construct(private readonly SeedLegalDrafts $seed)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $written = $this->seed->seed();
        if ($written > 0) {
            $output->writeln(\sprintf('Wrote %d legal draft%s.', $written, 1 === $written ? '' : 's'));
        }

        return Command::SUCCESS;
    }
}
