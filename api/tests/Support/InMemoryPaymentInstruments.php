<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Invoices\Domain\InstrumentPortfolioSearch;
use App\Module\Invoices\Domain\PaymentInstrument;
use App\Module\Invoices\Domain\PaymentInstrumentRepository;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

final class InMemoryPaymentInstruments implements PaymentInstrumentRepository
{
    /** @var list<PaymentInstrument> */
    public array $instruments = [];

    public function save(PaymentInstrument $instrument): void
    {
        if (!\in_array($instrument, $this->instruments, true)) {
            $this->instruments[] = $instrument;
        }
    }

    public function remove(PaymentInstrument $instrument): void
    {
        $this->instruments = array_values(array_filter($this->instruments, static fn (PaymentInstrument $each): bool => $each !== $instrument));
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?PaymentInstrument
    {
        foreach ($this->instruments as $instrument) {
            if ($instrument->getId()->equals($id) && $instrument->getCompany()->getId()->equals($companyId)) {
                return $instrument;
            }
        }

        return null;
    }

    public function ofInvoice(Uuid $companyId, Uuid $invoiceId): array
    {
        $mine = array_values(array_filter($this->instruments, static fn (PaymentInstrument $each): bool => $each->getCompany()->getId()->equals($companyId) && $each->getInvoice()->getId()->equals($invoiceId)));
        usort($mine, static fn (PaymentInstrument $a, PaymentInstrument $b): int => [$a->getDueOn(), $a->getId()->toRfc4122()] <=> [$b->getDueOn(), $b->getId()->toRfc4122()]);

        return $mine;
    }

    public function portfolio(Uuid $companyId, InstrumentPortfolioSearch $search, PageRequest $page): Page
    {
        $mine = array_values(array_filter($this->instruments, static fn (PaymentInstrument $each): bool => $each->getCompany()->getId()->equals($companyId) && ([] === $search->statuses || \in_array($each->getStatus(), $search->statuses, true))));
        usort($mine, static fn (PaymentInstrument $a, PaymentInstrument $b): int => [$a->getDueOn(), $a->getId()->toRfc4122()] <=> [$b->getDueOn(), $b->getId()->toRfc4122()]);

        return new Page(\array_slice($mine, $page->offset(), $page->size), \count($mine), $page);
    }

    public function openAmount(Uuid $companyId, Uuid $invoiceId): string
    {
        $sum = Decimal::zero();
        foreach ($this->ofInvoice($companyId, $invoiceId) as $instrument) {
            if ($instrument->getStatus()->isOpen()) {
                $sum = $sum->add(Decimal::of($instrument->getAmount()));
            }
        }

        return Decimal::format($sum, 3);
    }

    public function cashedByPayment(Uuid $companyId, Uuid $paymentId): ?PaymentInstrument
    {
        foreach ($this->instruments as $instrument) {
            if ($instrument->getCompany()->getId()->equals($companyId) && $paymentId->equals($instrument->getPayment()?->getId())) {
                return $instrument;
            }
        }

        return null;
    }
}
