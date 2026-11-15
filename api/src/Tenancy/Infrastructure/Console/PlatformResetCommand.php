<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Console;

use App\Fiscal\Infrastructure\Console\FiscalInventoryCommand;
use App\Fiscal\Infrastructure\Inventory\DbalFiscalInventory;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Infrastructure\Platform\PlatformContents;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `app:platform:reset`: an installation emptied in place, its schema kept, so it starts again as a new one (make
 * reset-empty, twes reset, deploy.sh reset run it in the migrate service, the one holding the owner's address, then
 * make the operator again with app:seed). Anything fiscal is never emptied in place: the database refuses to empty an
 * issued invoice while it is kept, and an installation holding one is reset whole, its volumes removed after its
 * export and backups are set aside, as no release of the fiscal guard covers a reset inside the keeping time. With nothing in it a confirmation is
 * asked; with any company or account, --force, then the installation's name typed. What was there is said as one
 * record, JSON with --json, which the wrappers keep outside the volumes, as the journal goes with the rest.
 */
#[AsCommand(name: 'app:platform:reset', description: 'Empty this installation in place (every table but the migrations list, every stored file); refused while anything fiscal is kept.')]
final class PlatformResetCommand extends Command
{
    public function __construct(
        private readonly DbalFiscalInventory $inventory,
        private readonly PlatformContents $contents,
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Empty an installation holding companies or accounts (its name is typed then)')
            ->addOption('installation', null, InputOption::VALUE_REQUIRED, 'The installation\'s name, typed back before anything is emptied')
            ->addOption('by', null, InputOption::VALUE_REQUIRED, 'Who resets it, kept in the record', 'console')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the reset\'s record as one JSON line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $companies = $this->inventory->companies();
        $fiscalCompanies = 0;
        $fiscalRecords = 0;
        foreach ($companies as $company) {
            $fiscalCompanies += $company['fiscal'] ? 1 : 0;
            foreach ($company['records'] as $line) {
                $fiscalRecords += 'fiscal' === $line['kind'] ? $line['count'] : 0;
            }
        }
        if ($fiscalCompanies > 0) {
            $io->error(\sprintf('%d fiscal records in %d of %d companies: an installation holding them is never emptied in place. It is reset whole, its volumes removed once its export and backups are set aside: twes reset, deploy.sh reset or make reset. Nothing emptied.', $fiscalRecords, $fiscalCompanies, \count($companies)));

            return FiscalInventoryCommand::FISCAL;
        }

        $accounts = Rows::int($this->connection->fetchOne('SELECT COUNT(*) FROM "user"'));
        $installation = $input->getOption('installation');
        $installation = \is_string($installation) && '' !== $installation ? $installation : null;
        if ([] !== $companies || $accounts > 0) {
            if (true !== $input->getOption('force')) {
                $io->error(\sprintf('%d companies and %d accounts are here: emptying them takes --force, then the installation\'s name typed. Nothing emptied.', \count($companies), $accounts));

                return Command::FAILURE;
            }
            if (null === $installation) {
                $io->error('Name the installation with --installation <name>, then type it back. Nothing emptied.');

                return Command::FAILURE;
            }
            if (!$input->isInteractive()) {
                $io->error('The installation\'s name is typed back, so this runs with its input. Nothing emptied.');

                return Command::FAILURE;
            }
            if ($installation !== $io->ask(\sprintf('%d companies and %d accounts are emptied, and every stored file. Type %s to go on', \count($companies), $accounts, $installation))) {
                $io->error('The name typed is not the installation\'s: nothing emptied.');

                return Command::FAILURE;
            }
        } elseif (true !== $input->getOption('force')) {
            if (!$input->isInteractive() || 'yes' !== $io->ask('No company and no account are here. Type yes to empty the rest')) {
                $io->error('Not confirmed: nothing emptied.');

                return Command::FAILURE;
            }
        }

        $emptied = $this->contents->empty();
        $by = $input->getOption('by');
        $record = [
            'scope' => 'installation',
            'mode' => 'in-place',
            'installation' => $installation,
            'by' => \is_string($by) ? $by : 'console',
            'at' => $this->clock->now()->format(\DATE_ATOM),
            'companies' => \count($companies),
            'accounts' => $accounts,
            'fiscalRecords' => $fiscalRecords,
            'tables' => $emptied['tables'],
            'files' => $emptied['files'],
        ];
        if (true === $input->getOption('json')) {
            $output->writeln(json_encode($record, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        } else {
            $io->success(\sprintf('Emptied: %d tables and %d stored files; make the operator again with app:seed.', $emptied['tables'], $emptied['files']));
        }

        return Command::SUCCESS;
    }
}
