<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\DeliveryNotes;

use App\Module\Customers\Domain\Model\Customer;
use App\Module\DeliveryNotes\Application\InvoiceDrafts;
use App\Module\DeliveryNotes\Application\InvoiceHeaderOfNotes;
use App\Module\DeliveryNotes\Application\InvoiceLineOfNote;
use App\Module\DeliveryNotes\Application\InvoiceToAddTo;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use App\Module\Invoices\Domain\ValueObject\InvoiceHeader;
use App\Module\Invoices\Domain\ValueObject\InvoiceLineDetails;
use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the delivery notes' `InvoiceDrafts` port through this module's own use case, audit and checks included: each
 * line of a note is checked here as any invoice line is, so one that no longer makes an invoice line is refused.
 */
final readonly class ManagedInvoiceDrafts implements InvoiceDrafts
{
    public function __construct(private ManageInvoices $manage, private InvoiceRepository $invoices)
    {
    }

    public function createFromLines(Company $company, Establishment $establishment, Customer $customer, InvoiceHeaderOfNotes $header, array $lines, array $origin, ?Uuid $actorUserId): Uuid
    {
        $header = new InvoiceHeader($header->supplyDate, customerReference: $header->customerReference);

        return $this->manage->createFromLines($company, $establishment, $customer, $header, self::lines($lines), $origin, $actorUserId)->getId();
    }

    public function appendLines(Company $company, Uuid $id, array $lines, array $origin, ?Uuid $actorUserId): void
    {
        $this->manage->appendLines($company, $id, self::lines($lines), $origin, $actorUserId);
    }

    public function ofId(Company $company, Uuid $id, bool $locked): ?InvoiceToAddTo
    {
        $invoice = $locked ? $this->invoices->lockedOfIdInCompany($id, $company->getId()) : $this->invoices->ofIdInCompany($id, $company->getId());

        return null === $invoice ? null : self::toAddTo($invoice);
    }

    private static function toAddTo(Invoice $invoice): InvoiceToAddTo
    {
        return new InvoiceToAddTo($invoice->getId(), $invoice->getCustomer()->getId(), $invoice->getEstablishment()->getId(), InvoiceStatus::Draft === $invoice->getStatus(), InvoiceType::Invoice === $invoice->getType() && !$invoice->isDeposit());
    }

    /**
     * @param list<InvoiceLineOfNote> $lines
     *
     * @return list<InvoiceLineDetails>
     */
    private static function lines(array $lines): array
    {
        return array_map(static fn (InvoiceLineOfNote $line): InvoiceLineDetails => new InvoiceLineDetails(
            $line->product,
            $line->description,
            $line->quantity,
            $line->unit,
            $line->unitPriceNet,
            null,
            $line->taxes,
            $line->deliveryNoteLineId,
            $line->lotCode,
        ), $lines);
    }
}
