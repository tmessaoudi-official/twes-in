<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Chain;

use App\Module\Invoices\Domain\Service\InvoiceChainStore;
use App\Module\Invoices\Domain\ValueObject\ChainSeries;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Numbering\AllocateNumber;

/**
 * Chains the issued documents of a series that are not sealed yet, in the order they were issued, after its last
 * sealed one (`InvoiceChain`). It always runs holding the series, as issuing does, so two writers never take one
 * place: issuing seals within its own transaction, and `all` seals what an earlier release issued, a slice per
 * transaction, which is safe to run again and on every start.
 */
final readonly class SealInvoiceChains
{
    /** Documents sealed per transaction when sealing everything. */
    private const int SLICE = 500;

    public function __construct(
        private InvoiceChainStore $chains,
        private AllocateNumber $numbers,
        private Transactions $transactions,
    ) {
    }

    /**
     * Seals what is unsealed in one series, inside the transaction that already holds it.
     *
     * @return int how many were sealed
     */
    public function series(ChainSeries $series, int $atMost = \PHP_INT_MAX): int
    {
        if (!$this->transactions->active()) {
            throw new \LogicException('A chain is sealed inside the transaction that holds its series.');
        }
        $head = $this->chains->head($series);
        $position = $head?->getChainPosition() ?? 0;
        $previous = $head?->getChainHash();
        $sealed = 0;
        while ($sealed < $atMost) {
            $unsealed = $this->chains->unsealed($series, min(self::SLICE, $atMost - $sealed));
            if ([] === $unsealed) {
                break;
            }
            foreach ($unsealed as $invoice) {
                $invoice->seal(++$position, $previous);
                $previous = $invoice->getChainHash();
            }
            $this->chains->recordHead($series, $position, $previous ?? throw new \LogicException('A sealed document has its hash.'));
            $this->chains->write();
            $sealed += \count($unsealed);
        }

        return $sealed;
    }

    /**
     * Seals every unsealed issued document, of all companies, holding each series while it is sealed.
     *
     * @return int how many were sealed
     */
    public function all(): int
    {
        $sealed = 0;
        foreach ($this->chains->series(null, unsealedOnly: true) as $series) {
            do {
                $slice = $this->transactions->run(function () use ($series): int {
                    $this->numbers->hold($series->establishmentId, $series->type->value);

                    return $this->series($series, self::SLICE);
                });
                $this->chains->forget();
                $sealed += $slice;
            } while (self::SLICE === $slice);
        }

        return $sealed;
    }
}
