<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Tenancy\Application\Numbering\NextNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\InvalidNumbering;
use Symfony\Component\Uid\Uuid;

/**
 * The number a draft would carry if it were issued now, said on the question before issuing, which cannot be undone
 * (docs/SPEC.md § 7, 2026-09-26 23:04: a precise preview before what is irreversible). Nothing is taken: issuing takes
 * the series' next number then, which is this one unless another document was issued in between.
 */
final readonly class NextInvoiceNumber
{
    public function __construct(private InvoiceRepository $invoices, private NextNumber $next)
    {
    }

    /**
     * @throws InvoiceNotFound
     * @throws InvoiceNotDraft
     * @throws NoNumberingSeries when the invoice's establishment numbers no document of its type
     * @throws InvalidNumbering  when the company's day comes before the month of the series' last number
     */
    public function of(Company $company, Uuid $id): string
    {
        $invoice = $this->invoices->ofIdInCompany($id, $company->getId()) ?? throw new InvoiceNotFound();
        $invoice->assertDraft('is numbered');

        return $this->next->of($company, $invoice->getEstablishment(), $invoice->getType()->value);
    }
}
