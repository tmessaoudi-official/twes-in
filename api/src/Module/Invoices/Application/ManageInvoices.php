<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\TaxKind;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Module\Products\Domain\ProductRepository;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's invoices while they are drafts. An invoice goes to one of the company's active customers from one of its
 * establishments; a line sells one of its active products or states what it is, in an active unit, with active line
 * taxes the customer's regime charges; the document carries active fixed charges and withholdings the regime charges,
 * by default the company's own and the customer's. A customer, product, unit or tax retired since stays with the draft
 * that already names it. The document must total. Audited with the names of the fields a revision changed, never their
 * values.
 */
final readonly class ManageInvoices
{
    public const string ENTITY_TYPE = 'invoice';
    public const string CREATED = 'invoice.created';
    public const string REVISED = 'invoice.revised';
    public const string CANCELLED = 'invoice.cancelled';

    public function __construct(
        private InvoiceRepository $invoices,
        private CustomerRepository $customers,
        private ProductRepository $products,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private EstablishmentRepository $establishments,
        private InvoiceTotals $totals,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<Invoice> */
    public function list(Company $company): array
    {
        return $this->invoices->ofCompany($company->getId());
    }

    /** @throws InvoiceNotFound */
    public function get(Company $company, Uuid $id): Invoice
    {
        return $this->invoices->ofIdInCompany($id, $company->getId()) ?? throw new InvoiceNotFound();
    }

    /** @throws InvalidInvoice */
    public function create(Company $company, InvoiceInput $input, ?Uuid $actorUserId): Invoice
    {
        [$establishment, $customer, $lines, $documentTaxes] = $this->checked($company, $input, null);
        $invoice = Invoice::create($company, $establishment, $customer, $input->header, $lines, $documentTaxes, $this->clock->now());
        $this->totals->checked($invoice);
        $this->invoices->save($invoice);
        $this->record($company, $invoice->getId(), self::CREATED, [], $actorUserId);

        return $invoice;
    }

    /**
     * @throws InvoiceNotFound
     * @throws InvoiceNotDraft
     * @throws InvalidInvoice
     */
    public function revise(Company $company, Uuid $id, InvoiceInput $input, ?Uuid $actorUserId): Invoice
    {
        $invoice = $this->get($company, $id);
        [$establishment, $customer, $lines, $documentTaxes] = $this->checked($company, $input, $invoice);
        $changed = $invoice->revise($establishment, $customer, $input->header, $lines, $documentTaxes, $this->clock->now());
        if ([] !== $changed) {
            $this->totals->checked($invoice);
            $this->invoices->save($invoice);
            $this->record($company, $invoice->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
        }

        return $invoice;
    }

    /**
     * @throws InvoiceNotFound
     * @throws InvoiceTransitionRefused
     */
    public function cancel(Company $company, Uuid $id, ?Uuid $actorUserId): Invoice
    {
        $invoice = $this->get($company, $id);
        $invoice->cancel($this->clock->now());
        $this->invoices->save($invoice);
        $this->record($company, $invoice->getId(), self::CANCELLED, [], $actorUserId);

        return $invoice;
    }

    /**
     * What the input names, found in the company and checked; what the invoice already names is kept even retired.
     *
     * @return array{Establishment, Customer, list<InvoiceLineDetails>, list<TaxComponent>}
     */
    private function checked(Company $company, InvoiceInput $input, ?Invoice $current): array
    {
        $customer = $this->customers->ofIdInCompany($input->customerId, $company->getId())
            ?? throw new InvalidInvoice('customerId', 'No customer of this company has this id.');
        if (!$customer->isActive() && !(null !== $current && $current->getCustomer()->getId()->equals($customer->getId()))) {
            throw new InvalidInvoice('customerId', \sprintf('The customer %s is deactivated.', $customer->getNumber()));
        }

        if (null === $input->establishmentId) {
            $establishment = array_find($this->establishments->ofCompany($company->getId()), static fn (Establishment $e): bool => $e->isDefault())
                ?? throw new \LogicException('A company always has a default establishment.');
        } else {
            $establishment = $this->establishments->ofIdInCompany($input->establishmentId, $company->getId())
                ?? throw new InvalidInvoice('establishmentId', 'No establishment of this company has this id.');
        }

        $kept = self::named($current);
        $lines = [];
        foreach ($input->lines as $index => $line) {
            try {
                $lines[] = $this->line($company, $customer, $line, $kept);
            } catch (InvalidInvoice $refused) {
                throw $refused->within("lines[$index]");
            }
        }

        return [$establishment, $customer, $lines, $this->documentTaxes($company, $customer, $input->documentTaxComponentIds, $kept['documentTaxes'])];
    }

    /** @param array{products: list<string>, units: list<string>, taxes: list<string>, documentTaxes: list<string>} $kept */
    private function line(Company $company, Customer $customer, InvoiceLineInput $line, array $kept): InvoiceLineDetails
    {
        $product = null;
        if (null !== $line->productId) {
            $product = $this->products->ofIdInCompany($line->productId, $company->getId())
                ?? throw new InvalidInvoice('productId', 'No product of this company has this id.');
            if (!$product->isActive() && !\in_array($product->getId()->toRfc4122(), $kept['products'], true)) {
                throw new InvalidInvoice('productId', \sprintf('The product %s is deactivated.', $product->getReference()));
            }
        }

        $unitId = $line->unitId ?? $product?->getUnit()->getId() ?? throw new InvalidInvoice('unitId', 'A line without a product names its unit.');
        $unit = $this->units->ofIdInCompany($unitId, $company->getId()) ?? throw new InvalidInvoice('unitId', 'No unit of this company has this id.');
        if (!$unit->isActive() && !\in_array($unit->getId()->toRfc4122(), $kept['units'], true)) {
            throw new InvalidInvoice('unitId', \sprintf('The unit %s is retired.', $unit->getCode()));
        }

        $price = $line->unitPriceNet ?? $product?->getDetails()->unitPriceNet ?? throw new InvalidInvoice('unitPriceNet', 'A line without a product states its price.');
        $description = null === $line->description || '' === trim($line->description) ? ($product?->getDetails()->name ?? '') : $line->description;

        return new InvoiceLineDetails($product, $description, $line->quantity, $unit, $price, $line->discountRate, $this->lineTaxes($company, $customer, $line, $product?->getDefaultTaxComponentIds(), $kept['taxes']));
    }

    /**
     * The taxes a line states, each checked; or, when it states none, its product's default line taxes its customer's
     * regime charges and the company still has active.
     *
     * @param list<string>|null $productDefaults
     * @param list<string>      $kept
     *
     * @return list<TaxComponent>
     */
    private function lineTaxes(Company $company, Customer $customer, InvoiceLineInput $line, ?array $productDefaults, array $kept): array
    {
        if (null === $line->taxComponentIds) {
            return $this->chargeable($company, $customer, $productDefaults ?? [], TaxKind::PercentageLine);
        }

        return $this->stated($company, $customer, $line->taxComponentIds, $kept, 'taxComponentIds');
    }

    /**
     * The document taxes an invoice states, each checked; or, when it states none, the company's active default fixed
     * charges and withholdings, then the customer's own, that its regime charges.
     *
     * @param list<Uuid>|null $stated
     * @param list<string>    $kept
     *
     * @return list<TaxComponent>
     */
    private function documentTaxes(Company $company, Customer $customer, ?array $stated, array $kept): array
    {
        if (null !== $stated) {
            return $this->stated($company, $customer, $stated, $kept, 'documentTaxComponentIds');
        }

        $companyDefaults = array_map(
            static fn (TaxComponent $tax): string => $tax->getId()->toRfc4122(),
            array_filter($this->taxes->ofCompany($company->getId()), static fn (TaxComponent $tax): bool => $tax->isDefault()),
        );
        $defaults = [];
        foreach ([...$this->chargeable($company, $customer, array_values($companyDefaults), null), ...$this->chargeable($company, $customer, $customer->getDefaultTaxComponentIds(), null)] as $tax) {
            if (TaxKind::PercentageLine !== $tax->getKind()) {
                $defaults[$tax->getId()->toRfc4122()] ??= $tax;
            }
        }

        return array_values($defaults);
    }

    /**
     * The active taxes among the ids that the customer's regime charges, of one kind when one is named.
     *
     * @param list<string> $ids
     *
     * @return list<TaxComponent>
     */
    private function chargeable(Company $company, Customer $customer, array $ids, ?TaxKind $kind): array
    {
        $excluded = $customer->getTaxRegime()->getExcludedFamilies();
        $taxes = array_map(fn (string $id): ?TaxComponent => $this->taxes->ofIdInCompany(Uuid::fromString($id), $company->getId()), $ids);

        return array_values(array_filter($taxes, static fn (?TaxComponent $tax): bool => null !== $tax
            && $tax->isActive()
            && (null === $kind || $kind === $tax->getKind())
            && !\in_array($tax->getFamily(), $excluded, true)));
    }

    /**
     * @param list<Uuid>   $ids
     * @param list<string> $kept
     *
     * @return list<TaxComponent>
     */
    private function stated(Company $company, Customer $customer, array $ids, array $kept, string $field): array
    {
        $taxes = [];
        foreach ($ids as $taxId) {
            $tax = $this->taxes->ofIdInCompany($taxId, $company->getId())
                ?? throw new InvalidInvoice($field, \sprintf('No tax of this company has the id %s.', $taxId->toRfc4122()));
            if (!$tax->isActive() && !\in_array($taxId->toRfc4122(), $kept, true)) {
                throw new InvalidInvoice($field, \sprintf('The tax %s is retired.', $tax->getCode()));
            }
            if (\in_array($tax->getFamily(), $customer->getTaxRegime()->getExcludedFamilies(), true)) {
                throw new InvalidInvoice($field, \sprintf('The %s regime does not charge %s.', $customer->getTaxRegime()->getCode(), $tax->getCode()));
            }
            $taxes[] = $tax;
        }

        return $taxes;
    }

    /**
     * The products, units, line taxes and document taxes a draft already names, by id.
     *
     * @return array{products: list<string>, units: list<string>, taxes: list<string>, documentTaxes: list<string>}
     */
    private static function named(?Invoice $invoice): array
    {
        $named = ['products' => [], 'units' => [], 'taxes' => [], 'documentTaxes' => []];
        foreach ($invoice?->getLines() ?? [] as $line) {
            $product = $line->getProduct();
            if (null !== $product) {
                $named['products'][] = $product->getId()->toRfc4122();
            }
            $named['units'][] = $line->getUnit()->getId()->toRfc4122();
            foreach ($line->getTaxes() as $tax) {
                $named['taxes'][] = $tax->getTaxComponent()->getId()->toRfc4122();
            }
        }
        foreach ($invoice?->getDocumentTaxes() ?? [] as $tax) {
            $named['documentTaxes'][] = $tax->getTaxComponent()->getId()->toRfc4122();
        }

        return $named;
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $invoiceId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $invoiceId, $action, $actorUserId, $changes, $company->getId()));
    }
}
