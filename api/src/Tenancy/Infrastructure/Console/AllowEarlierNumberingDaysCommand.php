<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Console;

use App\Tenancy\Application\Numbering\AllowEarlierNumberingDays;
use App\Tenancy\Application\Numbering\EarlierDaysRefused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/** docs/RUNBOOK.md « A shop's clock ran ahead »: run on the installation once its clock is right again. */
#[AsCommand(name: 'app:numbering:allow-earlier-days', description: 'Let every numbering series of a company take a day before its last number\'s for a few hours, after its computer\'s clock ran ahead.')]
final class AllowEarlierNumberingDaysCommand extends Command
{
    public function __construct(private readonly AllowEarlierNumberingDays $allow)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('company', InputArgument::REQUIRED, 'The company\'s id.');
        $this->addArgument('hours', InputArgument::REQUIRED, 'For how many hours.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $company = $input->getArgument('company');
        $hours = $input->getArgument('hours');
        if (!\is_string($company) || !Uuid::isValid($company) || !\is_string($hours) || !ctype_digit($hours)) {
            $output->writeln('<error>Give a company id and a whole number of hours.</error>');

            return Command::FAILURE;
        }
        try {
            $until = $this->allow->handle(Uuid::fromString($company), (int) $hours, null);
        } catch (EarlierDaysRefused $refused) {
            $output->writeln('<error>'.$refused->getMessage().'</error>');

            return Command::FAILURE;
        }
        $output->writeln(\sprintf('earlier days allowed until %s', $until->format(\DATE_ATOM)));

        return Command::SUCCESS;
    }
}
