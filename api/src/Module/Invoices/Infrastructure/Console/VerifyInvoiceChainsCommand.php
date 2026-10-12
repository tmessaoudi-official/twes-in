<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Console;

use App\Module\Invoices\Application\Chain\VerifyInvoiceChains;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

/** Walks the issued documents' chains and lists every place one does not hold; fails when there is any. */
#[AsCommand(name: 'app:invoices:verify-chain', description: 'Check the issued documents\' hash chains; fails on any break.')]
final class VerifyInvoiceChainsCommand extends Command
{
    public function __construct(private readonly VerifyInvoiceChains $verify)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('company', null, InputOption::VALUE_REQUIRED, 'Only this company\'s id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $company = $input->getOption('company');
        if (null !== $company && (!\is_string($company) || !Uuid::isValid($company))) {
            $output->writeln('<error>--company takes a company\'s id.</error>');

            return Command::INVALID;
        }
        $breaks = null === $company ? $this->verify->all() : $this->verify->of(Uuid::fromString($company));
        foreach ($breaks as $break) {
            $output->writeln(\sprintf(
                '%s company %s establishment %s %s place %s: %s',
                $break->reason,
                $break->series->companyId,
                $break->series->establishmentId,
                $break->series->type->value,
                $break->position ?? '-',
                $break->number ?? '-',
            ));
        }
        if ([] !== $breaks) {
            return Command::FAILURE;
        }
        $output->writeln('Every chain holds.');

        return Command::SUCCESS;
    }
}
