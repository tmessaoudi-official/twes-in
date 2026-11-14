<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Console;

use App\Fiscal\Infrastructure\Inventory\DbalFiscalInventory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * What a wipe reads before it removes anything: per company, the issued documents and the records of their ledger, and
 * what the shop or the law keeps beside them. Read-only. Exits 3 when anything fiscal is there and 0 when nothing is,
 * so a script refuses on the code alone; `--json` gives the counts a confirmation sentence names.
 */
#[AsCommand(name: 'app:fiscal:inventory', description: 'Count each company\'s issued documents and their ledger before a wipe; exits 3 when any is there, 0 when none.')]
final class FiscalInventoryCommand extends Command
{
    /** The exit a script reads as « fiscal records are there ». */
    public const int FISCAL = 3;

    public function __construct(private readonly DbalFiscalInventory $inventory)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('company', null, InputOption::VALUE_REQUIRED, 'Only this company (its id)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the inventory as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $only = $input->getOption('company');
        if (null !== $only && (!\is_string($only) || !Uuid::isValid($only))) {
            $io->error(\sprintf('No company %s: give its id.', \is_string($only) ? $only : '?'));

            return Command::FAILURE;
        }
        $only = null === $only ? null : Uuid::fromString($only)->toRfc4122();
        $companies = $this->inventory->companies($only);
        if (null !== $only && [] === $companies) {
            $io->error(\sprintf('No company %s.', $only));

            return Command::FAILURE;
        }
        $fiscal = [] !== array_filter($companies, static fn (array $company): bool => $company['fiscal']);
        $platform = $this->inventory->platform();

        if (true === $input->getOption('json')) {
            $output->writeln(json_encode(['fiscal' => $fiscal, 'companies' => $companies, 'platform' => $platform], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));

            return $fiscal ? self::FISCAL : Command::SUCCESS;
        }

        foreach ($companies as $company) {
            $io->section(\sprintf('%s (%s), %s%s%s', $company['name'], $company['id'], $company['status'], null === $company['retainUntil'] ? '' : ', kept until '.$company['retainUntil'], $company['fiscal'] ? ', FISCAL' : ''));
            $io->table(['Member', 'Kind', 'Count', 'PDFs', 'Numbers', 'Days'], array_map(static fn (array $line): array => [
                $line['code'],
                $line['kind'],
                $line['count'],
                $line['pdfs'] ?? '',
                null === $line['firstNumber'] ? '' : $line['firstNumber'].' … '.$line['lastNumber'],
                null === $line['from'] ? '' : $line['from'].' … '.$line['to'],
            ], $company['records']));
        }
        $io->section('Platform');
        $io->table(['Member', 'Kind', 'Count'], array_map(static fn (array $line): array => [$line['code'], $line['kind'], $line['count']], $platform));
        if ($fiscal) {
            $io->warning(\sprintf('Fiscal records: %d of %d companies hold issued documents or their ledger.', \count(array_filter($companies, static fn (array $company): bool => $company['fiscal'])), \count($companies)));

            return self::FISCAL;
        }
        $io->success('No fiscal record.');

        return Command::SUCCESS;
    }
}
