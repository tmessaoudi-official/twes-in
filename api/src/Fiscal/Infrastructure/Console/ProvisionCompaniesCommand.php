<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Console;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Domain\CompanyRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Brings every company up to its fiscal preset: what a company lacks is copied, what it has is never touched.
 * `infra/api/docker-entrypoint.sh` runs it after the migrations at every start, so a release whose preset numbers a
 * new kind of document gives every existing company that series; a migration cannot, as it cannot read the presets.
 */
#[AsCommand(name: 'app:companies:provision', description: 'Copy into every company what its fiscal preset has and it lacks (idempotent).')]
final class ProvisionCompaniesCommand extends Command
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly ProvisionCompany $provision,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->companies->all() as $company) {
            $copied = $this->provision->handle($company);
            if ([] !== $copied) {
                $output->writeln('Provisioned: '.implode(', ', $copied).'.');
            }
        }

        return Command::SUCCESS;
    }
}
