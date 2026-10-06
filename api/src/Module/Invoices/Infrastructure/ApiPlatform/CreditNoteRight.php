<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceType;
use App\Tenancy\Domain\Company;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * A credit note reverses revenue, so whatever changes one, issuing, revising or cancelling its draft, takes the right to
 * draft one besides the step's own. Whoever lacks it gets the answer a stranger gets.
 */
final readonly class CreditNoteRight
{
    public function __construct(private CompanyGuard $guard, private InvoiceRepository $invoices)
    {
    }

    /** @throws NotFoundHttpException when the document is a credit note and the caller may not draft one */
    public function check(Company $company, Uuid $invoiceId): void
    {
        $document = $this->invoices->ofIdInCompany($invoiceId, $company->getId());
        if (InvoiceType::CreditNote === $document?->getType() && !$this->guard->may($company, InvoicePermission::CREDIT)) {
            throw new NotFoundHttpException('No such invoice.');
        }
    }
}
