<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Fiscal\Application\Inventory\DeclaresFiscalRecords;
use App\Fiscal\Application\Inventory\FiscalInventoryCatalogue;
use App\Fiscal\Application\Inventory\FiscalRecords;
use App\Fiscal\Infrastructure\Inventory\FiscalInventoryCoverage;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The inventory a wipe reads before it removes anything must know every table that can hold an issued document or
 * what it left behind: a table with an issued state (a status, a number, an issue stamp, a chain, a stored PDF), or one
 * pointing at a table the inventory counts, is either counted or said not to be fiscal, and why. Otherwise a module
 * adding one would let a wipe find « nothing issued » in a database that holds it.
 */
final class FiscalInventoryCoverageTest extends KernelTestCase
{
    public function testEveryTableHoldingAnIssuedStateOrPointingAtACountedOneIsAccountedFor(): void
    {
        self::assertSame([], FiscalInventoryCoverage::unaccounted($this->connection(), $this->catalogue()->declarations()));
    }

    public function testATableWithAnIssuedStateThatNoMemberCountsIsNamed(): void
    {
        $without = $this->without(static fn (FiscalRecords $records): bool => 'delivery_note' === $records->table);

        self::assertContains('delivery_note has an issued state (status, number, pdf_file_id) and is neither counted nor said not to be fiscal', FiscalInventoryCoverage::unaccounted($this->connection(), $without));
    }

    public function testATablePointingAtACountedOneIsNamed(): void
    {
        $without = $this->without(static fn (FiscalRecords $records): bool => 'payment' === $records->table);

        self::assertContains('payment.invoice_id points at invoice, which the inventory counts, and payment is neither counted nor said not to be fiscal', FiscalInventoryCoverage::unaccounted($this->connection(), $without));
    }

    public function testAStoredPdfNoMemberCountsIsNamed(): void
    {
        $without = $this->without(null, static fn (FiscalRecords $records): FiscalRecords => 'invoice' === $records->table ? $records->withoutPdf() : $records);

        self::assertContains('invoice.pdf_file_id holds a stored document and no member counts it', FiscalInventoryCoverage::unaccounted($this->connection(), $without));
    }

    public function testATableOrColumnAMemberNamesThatNoLongerExistsIsNamed(): void
    {
        $gone = new class implements DeclaresFiscalRecords {
            public function fiscalRecords(): array
            {
                return [FiscalRecords::fiscal('old.receipts', 'till_receipt', numberColumn: 'receipt_number')];
            }

            public function notFiscal(): array
            {
                return ['till_drawer' => 'gone too'];
            }
        };

        $unaccounted = FiscalInventoryCoverage::unaccounted($this->connection(), [...$this->catalogue()->declarations(), $gone]);
        self::assertContains('till_receipt no longer exists, and old.receipts still counts it', $unaccounted);
        self::assertContains('till_drawer no longer exists, and is still said not to be fiscal', $unaccounted);
    }

    public function testEveryMemberCodeIsDeclaredOnce(): void
    {
        $codes = array_map(static fn (FiscalRecords $records): string => $records->code, $this->catalogue()->records());

        self::assertSame(array_values(array_unique($codes)), $codes);
        self::assertContains('invoices.issued', $codes);
    }

    /**
     * The declarations with some members left out or changed, as a module forgetting them would leave them.
     *
     * @param ?callable(FiscalRecords): bool          $drop
     * @param ?callable(FiscalRecords): FiscalRecords $change
     *
     * @return list<DeclaresFiscalRecords>
     */
    private function without(?callable $drop, ?callable $change = null): array
    {
        $records = [];
        $notFiscal = [];
        foreach ($this->catalogue()->declarations() as $declaration) {
            foreach ($declaration->fiscalRecords() as $member) {
                if (null === $drop || !$drop($member)) {
                    $records[] = null === $change ? $member : $change($member);
                }
            }
            $notFiscal = [...$notFiscal, ...$declaration->notFiscal()];
        }

        return [new readonly class($records, $notFiscal) implements DeclaresFiscalRecords {
            /**
             * @param list<FiscalRecords>   $records
             * @param array<string, string> $notFiscal
             */
            public function __construct(private array $records, private array $notFiscal)
            {
            }

            public function fiscalRecords(): array
            {
                return $this->records;
            }

            public function notFiscal(): array
            {
                return $this->notFiscal;
            }
        }];
    }

    private function catalogue(): FiscalInventoryCatalogue
    {
        return static::getContainer()->get(FiscalInventoryCatalogue::class);
    }

    private function connection(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
