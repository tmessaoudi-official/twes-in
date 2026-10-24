<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\DeliveryNotes;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Module\DeliveryNotes\Application\Invoices\DeliverableInvoices;
use App\Module\DeliveryNotes\Application\Invoices\InvoiceLineToDeliver;
use App\Module\DeliveryNotes\Application\Invoices\InvoiceToDeliver;
use App\Module\Invoices\Application\Delivery\GoodsToDeliver;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Model\InvoiceLine;
use App\Module\Invoices\Domain\Model\InvoiceLineTax;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the delivery notes' `DeliverableInvoices` port from this module's invoices: each with the lines whose goods it
 * billed itself, which left stock when it was issued, and what of each its issued credit notes took back into stock, as
 * `GoodsToDeliver` says them for the invoice's own page too.
 */
final readonly class InvoicesToDeliver implements DeliverableInvoices
{
    public function __construct(private InvoiceRepository $invoices)
    {
    }

    public function ofId(Company $company, Uuid $invoiceId, bool $locked): ?InvoiceToDeliver
    {
        $invoice = $locked ? $this->invoices->lockedOfIdInCompany($invoiceId, $company->getId()) : $this->invoices->ofIdInCompany($invoiceId, $company->getId());

        return null === $invoice ? null : self::toDeliver($invoice);
    }

    public function ofLines(Company $company, array $invoiceLineIds, bool $locked): array
    {
        return array_map(self::toDeliver(...), $this->invoices->ofLineIdsInCompany($invoiceLineIds, $company->getId(), $locked));
    }

    private static function toDeliver(Invoice $invoice): InvoiceToDeliver
    {
        $returned = GoodsToDeliver::returned($invoice);
        $wholeDiscounted = self::lowers($invoice->getHeader()->discountAmount);

        return new InvoiceToDeliver(
            $invoice->getId(),
            $invoice->getNumber(),
            InvoiceStatus::Draft !== $invoice->getStatus() && InvoiceStatus::Cancelled !== $invoice->getStatus(),
            InvoiceType::Invoice === $invoice->getType() && !$invoice->isDeposit(),
            $invoice->getCustomer(),
            $invoice->getEstablishment(),
            $invoice->getHeader()->customerReference,
            array_map(static fn (InvoiceLine $line): InvoiceLineToDeliver => new InvoiceLineToDeliver(
                $line->getId(),
                $line->getPosition(),
                $line->getProduct() ?? throw new \LogicException('A line of goods names its product.'),
                $line->getDescription(),
                $line->getQuantity(),
                $line->getUnit(),
                $line->getUnitPriceNet(),
                array_map(static fn (InvoiceLineTax $tax): TaxComponent => $tax->getTaxComponent(), $line->getTaxes()),
                $returned[$line->getId()->toRfc4122()] ?? '0.000',
                $wholeDiscounted || self::lowers($line->getDiscountRate()) || self::lowers($line->getDiscountAmount()),
            ), GoodsToDeliver::lines($invoice)),
        );
    }

    private static function lowers(?string $discount): bool
    {
        return null !== $discount && 0 !== Decimal::of($discount)->compare(Decimal::zero());
    }
}
