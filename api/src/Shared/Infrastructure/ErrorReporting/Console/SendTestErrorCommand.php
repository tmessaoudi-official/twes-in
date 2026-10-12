<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ErrorReporting\Console;

use App\Shared\Infrastructure\ErrorReporting\TestError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fails on purpose, so whoever set up the error tracker sees a report arrive (docs/START.md § 15). Symfony logs the
 * failed command at error level, which is the one road reports take. Its message carries an address, a quoted value
 * and a long number, all made up, so the report read back shows each one masked.
 */
#[AsCommand(name: 'app:errors:test', description: 'Fail on purpose, so a report reaches the error tracker with its values masked.')]
final class SendTestErrorCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        throw new TestError('Test error from app:errors:test: nobody@twes.invalid, "a quoted value", 123456789');
    }
}
