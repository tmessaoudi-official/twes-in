<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Console;

use App\Demo\Application\SwitchDemoOffAtInstall;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/** What scripts/onprem/twes install runs once the operator exists (idempotent). */
#[AsCommand(name: 'app:demo:switch-off-at-install', description: 'Switch the demo off on a shop\'s installation, unless an operator has chosen already (idempotent).')]
final class SwitchDemoOffAtInstallCommand extends Command
{
    public function __construct(private readonly SwitchDemoOffAtInstall $switchOff)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln($this->switchOff->handle() ? 'demo switched off' : 'demo left as chosen');

        return Command::SUCCESS;
    }
}
