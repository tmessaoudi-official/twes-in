<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Installation\Infrastructure\Console;

use App\Installation\Application\InstallationLineRefused;
use App\Installation\Application\TellInstallation;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The installation script's way in (scripts/onprem/twes). A refused line exits INVALID (2), which the script reads as
 * « never deliverable » and drops, where any other failure means the application is not ready yet and the line waits.
 */
#[AsCommand(name: 'app:installation:tell', description: 'Tell the owners or the operators what the installation script saw (scripts/onprem/twes).')]
final class TellInstallationCommand extends Command
{
    public function __construct(private readonly TellInstallation $tell)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('kind', InputArgument::REQUIRED, 'One of: '.implode(', ', TellInstallation::kinds()))
            ->addArgument('parameters', InputArgument::IS_ARRAY, 'name=value, as the kind takes them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $kind = $input->getArgument('kind');
        $parameters = $input->getArgument('parameters');
        if (!\is_string($kind) || !\is_array($parameters)) {
            return Command::INVALID;
        }
        $lines = [];
        foreach ($parameters as $parameter) {
            if (!\is_string($parameter)) {
                return Command::INVALID;
            }
            $lines[] = $parameter;
        }
        try {
            $told = $this->tell->handle($kind, $lines);
        } catch (InstallationLineRefused $refused) {
            $output->writeln('<error>'.$refused->getMessage().'</error>');

            return Command::INVALID;
        }
        $output->writeln(\sprintf('%s told to %d.', $kind, $told));

        return Command::SUCCESS;
    }
}
