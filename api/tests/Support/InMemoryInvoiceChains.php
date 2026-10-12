<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Invoices\Domain\ChainSeries;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceChainHead;
use App\Module\Invoices\Domain\InvoiceChainStore;
use Symfony\Component\Uid\Uuid;

/** The chains over the invoices an `InMemoryInvoices` holds, read as the Doctrine store reads them. */
final class InMemoryInvoiceChains implements InvoiceChainStore
{
    /** @var array<string, InvoiceChainHead> */
    private array $heads = [];

    public function __construct(private readonly InMemoryInvoices $invoices)
    {
    }

    public function series(?Uuid $companyId, bool $unsealedOnly = false): array
    {
        $series = [];
        foreach ($this->invoices->invoices as $invoice) {
            if (null === $invoice->getNumber() || ($unsealedOnly && null !== $invoice->getChainHash())
                || (null !== $companyId && !$invoice->getCompany()->getId()->equals($companyId))) {
                continue;
            }
            $one = new ChainSeries($invoice->getCompany()->getId(), $invoice->getEstablishment()->getId(), $invoice->getType());
            $series[$one->companyId.'|'.$one->establishmentId.'|'.$one->type->value] = $one;
        }
        ksort($series);

        return array_values($series);
    }

    public function head(ChainSeries $series): ?Invoice
    {
        $sealed = $this->sealed($series, 0, \PHP_INT_MAX);

        return [] === $sealed ? null : $sealed[\count($sealed) - 1];
    }

    public function unsealed(ChainSeries $series, int $limit): array
    {
        $unsealed = array_values(array_filter($this->of($series), static fn (Invoice $invoice): bool => null === $invoice->getChainHash()));
        usort($unsealed, static fn (Invoice $a, Invoice $b): int => [$a->getIssueDate(), \strlen((string) $a->getNumber()), $a->getNumber(), $a->getId()->toRfc4122()] <=> [$b->getIssueDate(), \strlen((string) $b->getNumber()), $b->getNumber(), $b->getId()->toRfc4122()]);

        return \array_slice($unsealed, 0, $limit);
    }

    public function sealed(ChainSeries $series, int $afterPosition, int $limit): array
    {
        $sealed = array_values(array_filter($this->of($series), static fn (Invoice $invoice): bool => ($invoice->getChainPosition() ?? 0) > $afterPosition));
        usort($sealed, static fn (Invoice $a, Invoice $b): int => $a->getChainPosition() <=> $b->getChainPosition());

        return \array_slice($sealed, 0, $limit);
    }

    public function recordedHead(ChainSeries $series): ?InvoiceChainHead
    {
        return $this->heads[$series->establishmentId.'|'.$series->type->value] ?? null;
    }

    public function recordHead(ChainSeries $series, int $position, string $hash): void
    {
        $head = $this->recordedHead($series);
        if (null !== $head) {
            $head->moveTo($position, $hash);

            return;
        }
        $one = $this->of($series)[0] ?? throw new \LogicException('A head is recorded for a series with documents.');
        $this->heads[$series->establishmentId.'|'.$series->type->value] = InvoiceChainHead::at($one->getCompany(), $one->getEstablishment(), $series->type, $position, $hash);
    }

    public function write(): void
    {
    }

    public function forget(): void
    {
    }

    /** @return list<Invoice> */
    private function of(ChainSeries $series): array
    {
        return array_values(array_filter($this->invoices->invoices, static fn (Invoice $invoice): bool => null !== $invoice->getNumber()
            && $invoice->getCompany()->getId()->equals($series->companyId)
            && $invoice->getEstablishment()->getId()->equals($series->establishmentId)
            && $invoice->getType() === $series->type));
    }
}
