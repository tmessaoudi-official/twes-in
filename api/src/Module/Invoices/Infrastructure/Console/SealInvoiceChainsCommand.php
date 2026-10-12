<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Console;

use App\Module\Invoices\Application\Chain\SealInvoiceChains;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Chains the issued documents no chain holds yet, in their issue order: those issued before the chain existed, once.
 * `infra/api/docker-entrypoint.sh` runs it after the migrations at every start; the API and the worker may both run
 * it at once, and each series is held while it is sealed, so running it again, or twice together, seals nothing twice.
 */
#[AsCommand(name: 'app:invoices:seal-chain', description: 'Chain the issued documents that are not chained yet, in issue order (idempotent).')]
final class SealInvoiceChainsCommand extends Command
{
    public function __construct(private readonly SealInvoiceChains $seal)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sealed = $this->seal->all();
        if ($sealed > 0) {
            $output->writeln(\sprintf('Chained %d issued document%s.', $sealed, 1 === $sealed ? '' : 's'));
        }

        return Command::SUCCESS;
    }
}
