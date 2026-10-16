<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Chain;

use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Service\InvoiceChainStore;
use App\Module\Invoices\Domain\ValueObject\ChainSeries;
use Symfony\Component\Uid\Uuid;

/**
 * Walks each series' chain from its first place and says where it does not hold (`ChainBreak`): a document whose hash
 * no longer matches what it says, one that does not follow the one before it, a place missing or added, an
 * issued document never sealed, and a chain that does not end where it was last recorded to (`InvoiceChainHead`).
 * Read-only, a slice at a time, so it may run over a whole database.
 */
final readonly class VerifyInvoiceChains
{
    private const int SLICE = 500;

    public function __construct(private InvoiceChainStore $chains)
    {
    }

    /** @return list<ChainBreak> */
    public function of(Uuid $companyId): array
    {
        return $this->walk($this->chains->series($companyId));
    }

    /** @return list<ChainBreak> */
    public function all(): array
    {
        return $this->walk($this->chains->series(null));
    }

    /**
     * @param list<ChainSeries> $series
     *
     * @return list<ChainBreak>
     */
    private function walk(array $series): array
    {
        $breaks = [];
        foreach ($series as $one) {
            $expected = 1;
            $previous = null;
            $after = 0;
            while ([] !== ($slice = $this->chains->sealed($one, $after, self::SLICE))) {
                foreach ($slice as $invoice) {
                    $position = $invoice->getChainPosition() ?? 0;
                    if ($position !== $expected) {
                        $breaks[] = new ChainBreak($one, $invoice->getNumber(), $position, ChainBreak::POSITION);
                    }
                    if ($invoice->getChainPrevious() !== $previous) {
                        $breaks[] = new ChainBreak($one, $invoice->getNumber(), $position, ChainBreak::LINK);
                    }
                    if (!self::holds($invoice)) {
                        $breaks[] = new ChainBreak($one, $invoice->getNumber(), $position, ChainBreak::HASH);
                    }
                    $expected = $position + 1;
                    $previous = $invoice->getChainHash();
                    $after = $position;
                }
                $this->chains->forget();
            }
            // The end the chain was recorded to have: a chain that stops short of it lost its last documents.
            $head = $this->chains->recordedHead($one);
            if (null === $head ? 1 !== $expected : ($head->getPosition() !== $expected - 1 || $head->getHash() !== $previous)) {
                $breaks[] = new ChainBreak($one, null, $head?->getPosition(), ChainBreak::HEAD);
            }
            foreach ($this->chains->unsealed($one, self::SLICE) as $invoice) {
                $breaks[] = new ChainBreak($one, $invoice->getNumber(), null, ChainBreak::UNSEALED);
            }
            $this->chains->forget();
        }

        return $breaks;
    }

    private static function holds(Invoice $invoice): bool
    {
        try {
            return $invoice->chainHolds();
        } catch (\LogicException) {
            // Sealed with a version this release cannot read, or missing what it was sealed over: it does not hold.
            return false;
        }
    }
}
