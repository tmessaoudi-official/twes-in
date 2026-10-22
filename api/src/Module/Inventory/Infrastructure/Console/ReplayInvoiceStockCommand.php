<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Console;

use App\Module\Inventory\Application\IssuedInvoices;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\Repository\StockMovementRepository;
use App\Module\Inventory\Infrastructure\Invoices\MoveStockOnInvoices;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Moves the stock of one issued invoice or credit note again, after the notification that its move failed, as a
 * delivery note's is (`app:stock:replay-delivery-note`). A person names the document; the move is the one its issue ran,
 * from the same lines, so a document whose stock already moved moves nothing more, and running it twice is safe.
 */
#[AsCommand(name: 'app:stock:replay-invoice', description: 'Move the stock of one issued invoice or credit note again, when its move failed.')]
final class ReplayInvoiceStockCommand extends Command
{
    public function __construct(
        private readonly IssuedInvoices $invoices,
        private readonly StockMovementRepository $movements,
        private readonly MoveStockOnInvoices $move,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('company', InputArgument::REQUIRED, 'Id of the company')
            ->addArgument('invoice', InputArgument::REQUIRED, 'Id of the invoice or credit note, as the notification names it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        [$companyId, $invoiceId] = [self::argument($input, 'company'), self::argument($input, 'invoice')];
        if (!Uuid::isValid($companyId) || !Uuid::isValid($invoiceId)) {
            $io->error('The company and the invoice are named by their ids.');

            return Command::FAILURE;
        }
        $company = Uuid::fromString($companyId);
        $issued = $this->invoices->issued($company, Uuid::fromString($invoiceId));
        if (null === $issued) {
            $io->error('No issued invoice or credit note of this company has this id: a draft moves no stock.');

            return Command::FAILURE;
        }
        $source = InvoiceType::CreditNote === $issued->type ? StockMovement::SOURCE_CREDIT_NOTE : StockMovement::SOURCE_INVOICE;
        if ($this->written($source, $issued->invoiceId, $company) > 0) {
            $io->success(\sprintf('The stock of %s already moved: nothing to do.', $issued->number));

            return Command::SUCCESS;
        }

        // The listener the issue ran: what it cannot move it logs and tells the stock keepers again, as then.
        ($this->move)($issued);

        $moved = $this->written($source, $issued->invoiceId, $company);
        if (0 === $moved) {
            $io->warning(\sprintf('%s moved no stock: its lines keep none, or the move failed again (see the log and the stock keepers\' notifications).', $issued->number));

            return Command::SUCCESS;
        }
        $io->success(\sprintf('%s: %d movement%s written.', $issued->number, $moved, 1 === $moved ? '' : 's'));

        return Command::SUCCESS;
    }

    /**
     * Read before and after the move, which writes what the second read counts.
     *
     * @phpstan-impure
     */
    private function written(string $source, Uuid $documentId, Uuid $companyId): int
    {
        return \count($this->movements->ofSource($source, $documentId, $companyId));
    }

    private static function argument(InputInterface $input, string $name): string
    {
        $value = $input->getArgument($name);

        return \is_string($value) ? $value : '';
    }
}
