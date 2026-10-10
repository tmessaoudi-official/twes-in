<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\SectionedTotals;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\TaxKind;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLine;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceSearch;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
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
 * that already names it. A line invoices a delivery note line only when its draft already did: such a draft starts from
 * delivery notes. The document must total. Audited with the names of the fields a revision changed, never their values.
 */
final readonly class ManageInvoices
{
    public const string ENTITY_TYPE = 'invoice';
    public const string CREATED = 'invoice.created';
    public const string REVISED = 'invoice.revised';
    public const string CANCELLED = 'invoice.cancelled';

    public function __construct(
        private InvoiceRepository $invoices,
        private Transactions $transactions,
        private CustomerRepository $customers,
        private ProductRepository $products,
        private UnitRepository $units,
        private TaxComponentRepository $taxes,
        private EstablishmentRepository $establishments,
        private InvoiceTotals $totals,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private InvoiceLinePrices $linePrices,
        private ExcludedTaxFamilies $excluded,
        private SourceDeliveryNoteLines $sourceLines,
        private DepositDeductions $deductions,
        private FiscalPresets $presets,
    ) {
    }

    /** @return list<Invoice> */
    public function list(Company $company): array
    {
        return $this->invoices->ofCompany($company->getId());
    }

    /**
     * One page of the company's invoices and credit notes, as the list asked for them.
     *
     * @return Page<Invoice>
     */
    public function search(Company $company, InvoiceSearch $search, PageRequest $page): Page
    {
        return $this->invoices->search($company->getId(), $search, $page);
    }

    /**
     * What each status chip of the list would show under the search (docs/SPEC.md § 7, 2026-09-26), overdue on the
     * company's own day.
     *
     * @return array{all: int, statuses: array<string, int>}
     */
    public function statusCounts(Company $company, InvoiceSearch $search): array
    {
        return $this->invoices->statusCounts($company->getId(), $search, new \DateTimeImmutable('today', new \DateTimeZone($company->getTimezone())));
    }

    /** @throws InvoiceNotFound */
    public function get(Company $company, Uuid $id): Invoice
    {
        return $this->invoices->ofIdInCompany($id, $company->getId()) ?? throw new InvoiceNotFound();
    }

    /** Held until the transaction ends and read as it stands: a draft check on it cannot be overtaken by another request. */
    private function locked(Company $company, Uuid $id): Invoice
    {
        return $this->invoices->lockedOfIdInCompany($id, $company->getId()) ?? throw new InvoiceNotFound();
    }

    /** @throws InvalidInvoice */
    public function create(Company $company, InvoiceInput $input, ?Uuid $actorUserId): Invoice
    {
        return $this->transactions->run(function () use ($company, $input, $actorUserId): Invoice {
            [$establishment, $customer, $lines, $documentTaxes] = $this->checked($company, $input, null);
            $invoice = Invoice::create($company, $establishment, $customer, $input->header, $lines, $documentTaxes, $this->clock->now());
            $this->totals->checked($invoice);
            $this->invoices->save($invoice);
            $this->record($company, $invoice->getId(), self::CREATED, [], $actorUserId);

            return $invoice;
        });
    }

    /**
     * A draft of lines already written, such as a delivery note's (docs/SPEC.md § 7, 2026-09-14) or a quote's: its
     * document taxes are those a draft leaving them out has, and it is audited as created with what it was drafted from.
     * A quote's names it, and its deposit invoices say so.
     *
     * @param list<InvoiceLineDetails> $lines
     * @param array<string, mixed>     $origin
     *
     * @throws InvalidInvoice
     */
    public function createFromLines(Company $company, Establishment $establishment, Customer $customer, InvoiceHeader $header, array $lines, array $origin, ?Uuid $actorUserId, ?Uuid $quoteId = null, bool $deposit = false): Invoice
    {
        return $this->transactions->run(function () use ($company, $establishment, $customer, $header, $lines, $origin, $actorUserId, $quoteId, $deposit): Invoice {
            $invoice = Invoice::create($company, $establishment, $customer, $header, $lines, $this->documentTaxes($company, $customer, null, []), $this->clock->now(), $quoteId, $deposit);
            $this->totals->checked($invoice);
            $this->invoices->save($invoice);
            $this->record($company, $invoice->getId(), self::CREATED, $origin, $actorUserId);

            return $invoice;
        });
    }

    /**
     * Lines added to a draft after its own, audited as a revision of its lines with what they came from.
     *
     * @param list<InvoiceLineDetails> $lines
     * @param array<string, mixed>     $origin
     *
     * @throws InvoiceNotFound
     * @throws InvoiceNotDraft
     * @throws InvalidInvoice
     */
    public function appendLines(Company $company, Uuid $id, array $lines, array $origin, ?Uuid $actorUserId): Invoice
    {
        return $this->transactions->run(function () use ($company, $id, $lines, $origin, $actorUserId): Invoice {
            $invoice = $this->locked($company, $id);
            $invoice->addLines($lines, $this->clock->now());
            $this->totals->checked($invoice);
            $this->invoices->save($invoice);
            $this->record($company, $invoice->getId(), self::REVISED, ['fields' => ['lines'], ...$origin], $actorUserId);

            return $invoice;
        });
    }

    /**
     * A copy of a document as a new draft; the answer is the copy, which is where the person continues.
     *
     * @throws InvoiceNotFound
     * @throws InvoiceTransitionRefused
     * @throws InvalidInvoice
     */
    public function duplicate(Company $company, Uuid $invoiceId, ?Uuid $actorUserId): Invoice
    {
        return $this->transactions->run(function () use ($company, $invoiceId, $actorUserId): Invoice {
            $copy = Invoice::duplicateOf($this->get($company, $invoiceId), $this->clock->now());
            $this->totals->checked($copy);
            $this->invoices->save($copy);
            $this->record($company, $copy->getId(), self::CREATED, ['duplicateOfInvoiceId' => $invoiceId->toRfc4122()], $actorUserId);

            return $copy;
        });
    }

    /**
     * A copy of a document as a new draft made by the worker for a recurring invoice, audited as created with where it
     * came from and by no one.
     *
     * @param array<string, mixed> $origin
     *
     * @throws InvoiceNotFound
     * @throws InvoiceTransitionRefused
     * @throws InvalidInvoice
     */
    public function draftFromModel(Company $company, Uuid $modelInvoiceId, array $origin): Invoice
    {
        return $this->transactions->run(function () use ($company, $modelInvoiceId, $origin): Invoice {
            $copy = Invoice::duplicateOf($this->get($company, $modelInvoiceId), $this->clock->now());
            $this->totals->checked($copy);
            $this->invoices->save($copy);
            $this->record($company, $copy->getId(), self::CREATED, $origin, null);

            return $copy;
        });
    }

    /**
     * A credit note drafted from an issued invoice (docs/SPEC.md § 7, 2026-09-14), stating why (2026-09-24 22:51), audited
     * as created with the invoice it corrects.
     *
     * @throws InvoiceNotFound
     * @throws InvoiceTransitionRefused when the document is not an issued invoice
     * @throws InvalidInvoice
     */
    public function draftCreditNote(Company $company, Uuid $invoiceId, string $reason, ?Uuid $actorUserId): Invoice
    {
        return $this->transactions->run(function () use ($company, $invoiceId, $reason, $actorUserId): Invoice {
            $credit = Invoice::creditNoteFor($this->get($company, $invoiceId), $reason, $this->clock->now());
            $this->totals->checked($credit);
            $this->invoices->save($credit);
            $this->record($company, $credit->getId(), self::CREATED, ['correctsInvoiceId' => $invoiceId->toRfc4122()], $actorUserId);

            return $credit;
        });
    }

    /**
     * @throws InvoiceNotFound
     * @throws InvoiceNotDraft
     * @throws InvalidInvoice
     */
    public function revise(Company $company, Uuid $id, InvoiceInput $input, ?Uuid $actorUserId): Invoice
    {
        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId): Invoice {
            $invoice = $this->locked($company, $id);
            [$establishment, $customer, $lines, $documentTaxes] = $this->checked($company, $input, $invoice);
            $changed = $invoice->revise($establishment, $customer, $input->header, $lines, $documentTaxes, $this->clock->now());
            if ([] !== $changed) {
                $this->totals->checked($invoice);
                $this->invoices->save($invoice);
                $this->record($company, $invoice->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $invoice;
        });
    }

    /**
     * The figures a new invoice, or the draft `$id` revised, would have, from what a save would send: checked as the
     * save checks it, worked out by the calculator that saves them, and kept nowhere. Nothing is locked, written or
     * audited.
     *
     * @throws InvoiceNotFound
     * @throws InvoiceNotDraft
     * @throws InvalidInvoice
     */
    public function preview(Company $company, InvoiceInput $input, ?Uuid $id): SectionedTotals
    {
        $current = null === $id ? null : $this->get($company, $id);
        $current?->assertDraft('changes');
        [$establishment, $customer, $lines, $documentTaxes] = $this->checked($company, $input, $current);
        $draft = null === $current
            ? Invoice::create($company, $establishment, $customer, $input->header, $lines, $documentTaxes, $this->clock->now())
            : $current->previewOf($establishment, $customer, $input->header, $lines, $documentTaxes, $this->clock->now());

        return new SectionedTotals($this->totals->checked($draft), array_map(static fn (InvoiceLine $line): ?string => $line->getSection(), $draft->getLines()));
    }

    /**
     * @throws InvoiceNotFound
     * @throws InvoiceTransitionRefused
     */
    public function cancel(Company $company, Uuid $id, ?Uuid $actorUserId): Invoice
    {
        return $this->transactions->run(function () use ($company, $id, $actorUserId): Invoice {
            $invoice = $this->locked($company, $id);
            $invoice->cancel($this->clock->now());
            $this->invoices->save($invoice);
            $this->record($company, $invoice->getId(), self::CANCELLED, [], $actorUserId);

            return $invoice;
        });
    }

    /**
     * What the input names, found in the company and checked; what the invoice already names is kept even retired.
     *
     * @return array{Establishment, Customer, list<InvoiceLineDetails>, list<TaxComponent>}
     */
    private function checked(Company $company, InvoiceInput $input, ?Invoice $current): array
    {
        if (null !== $input->header->operationCategory && !$this->presets->get($company->getFiscalPreset())->invoiceFields->operationCategory) {
            throw new InvalidInvoice('operationCategory', 'This company\'s invoices state no category of operations: its country\'s law asks for none.');
        }
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

        if (null !== $current && InvoiceType::Invoice === $current->getType()) {
            $this->withinTheirNotes($company, $current, $input->lines);
        }

        $kept = self::named($current);
        $lines = [];
        $givenBack = [];
        $language = null;
        // A deposit's lines are written again from it; the title each was sent with, and where, is read first.
        $titles = [];
        foreach ($input->lines as $index => $line) {
            if (null !== $line->deductsInvoiceId) {
                $titles[$line->deductsInvoiceId->toRfc4122()][] = [$index, $line->section];
            }
        }
        foreach ($input->lines as $index => $line) {
            try {
                if (null === $line->deductsInvoiceId) {
                    $lines[] = $this->line($company, $customer, $line, $kept);
                    continue;
                }
                // One line naming a deposit stands for all of it, written again from the deposit.
                if (isset($givenBack[$line->deductsInvoiceId->toRfc4122()])) {
                    continue;
                }
                $givenBack[$line->deductsInvoiceId->toRfc4122()] = true;
                if (null !== $current && InvoiceType::CreditNote === $current->getType()) {
                    $deposit = $this->keptOnACreditNote($current, $line->deductsInvoiceId);
                } else {
                    $language ??= $this->deductions->language($company, $customer);
                    $deposit = $this->deductions->linesGivingBack($company, $line->deductsInvoiceId, $current, $language);
                }
            } catch (InvalidInvoice $refused) {
                throw $refused->within("lines[$index]");
            }
            // Outside the line's own refusals: a title refused names the line it was sent on.
            foreach (self::depositTitles($titles[$line->deductsInvoiceId->toRfc4122()], $deposit, $current?->linesGivingBack($line->deductsInvoiceId) ?? []) as $position => [$at, $title]) {
                try {
                    $lines[] = $deposit[$position]->opening($title);
                } catch (InvalidInvoice $refused) {
                    throw $refused->within("lines[$at]");
                }
            }
        }

        return [$establishment, $customer, $lines, $this->documentTaxes($company, $customer, $input->documentTaxComponentIds, $kept['documentTaxes'])];
    }

    /**
     * The title each line written again from a deposit opens, with the input line it came from. Sent one line for one,
     * each takes the title sent in its place. Sent otherwise, a row having been taken off or the deposit just named,
     * a title can no longer be tied to a line: the document keeps the titles it holds, and a deposit it did not give
     * back yet opens the first title sent on its first line.
     *
     * @param non-empty-list<array{int, string|null}> $sent    each input line naming the deposit: its position and title
     * @param list<InvoiceLineDetails>                $deposit the deposit's lines, written again
     * @param list<InvoiceLineDetails>                $held    what the document held of the deposit before this change
     *
     * @return list<array{int, string|null}>
     */
    private static function depositTitles(array $sent, array $deposit, array $held): array
    {
        $first = $sent[0][0];
        if (\count($sent) === \count($deposit)) {
            return $sent;
        }
        if ([] !== $held) {
            return array_map(static fn (int $position): array => [$first, $held[$position]->section ?? null], array_keys($deposit));
        }

        return array_map(static fn (int $position): array => [$first, 0 === $position ? $sent[0][1] : null], array_keys($deposit));
    }

    /**
     * A credit note reverses what its invoice gave back of a deposit, and gives back nothing else.
     *
     * @return list<InvoiceLineDetails>
     *
     * @throws InvalidInvoice
     */
    private function keptOnACreditNote(Invoice $credit, Uuid $depositId): array
    {
        $lines = $credit->linesGivingBack($depositId);
        if ([] === $lines) {
            throw new InvalidInvoice('deductsInvoiceId', 'A credit note gives back only the deposits the invoice it corrects gave back.');
        }

        return $lines;
    }

    /**
     * A draft's line taken from a delivery note stays that line (docs/SPEC.md § 7, audit 2026-10-06 A-16): it keeps the
     * note line's product and invoices no more of it than the company's other invoices that are not cancelled leave.
     * Whether the draft carried the line at all is `line()`'s check, and a line named twice the domain's.
     *
     * @param list<InvoiceLineInput> $lines
     */
    private function withinTheirNotes(Company $company, Invoice $current, array $lines): void
    {
        $ids = [];
        foreach ($lines as $line) {
            if (null !== $line->sourceDeliveryNoteLineId) {
                $ids[$line->sourceDeliveryNoteLineId->toRfc4122()] = $line->sourceDeliveryNoteLineId;
            }
        }
        [$sources, $room] = $this->room($company, $current, array_values($ids), true);
        foreach ($lines as $index => $line) {
            $id = $line->sourceDeliveryNoteLineId?->toRfc4122();
            if (null === $id || !isset($sources[$id], $room[$id])) {
                continue;
            }
            $source = $sources[$id];
            if (!(null === $source->productId ? null === $line->productId : null !== $line->productId && $source->productId->equals($line->productId))) {
                throw (new InvalidInvoice('productId', 'A line taken from a delivery note keeps the product the note delivered.'))->within("lines[$index]");
            }
            if (is_numeric($line->quantity) && Decimal::of($line->quantity)->compare(Decimal::of($room[$id])) > 0) {
                throw (new InvalidInvoice('quantity', \sprintf('Only %s of this delivery note line is left to invoice.', $room[$id])))->within("lines[$index]");
            }
        }
    }

    /**
     * The most each of a draft invoice's lines taken from a delivery note may invoice, by delivery note line id, so a
     * screen caps it where the revision would (docs/SPEC.md § 7, audit 2026-10-06 A-16). Empty for anything else.
     *
     * @return array<string, string> decimal quantities, at the scale of a quantity
     */
    public function roomOnSources(Company $company, Invoice $invoice): array
    {
        if (InvoiceStatus::Draft !== $invoice->getStatus() || InvoiceType::Invoice !== $invoice->getType()) {
            return [];
        }

        return $this->room($company, $invoice, array_values(array_filter(array_map(static fn (InvoiceLine $line): ?Uuid => $line->getSourceDeliveryNoteLineId(), $invoice->getLines()))))[1];
    }

    /**
     * @param list<Uuid> $ids  delivery note lines
     * @param bool       $held whether what is read must stay so until the transaction ends, for a revision that relies on it
     *
     * @return array{array<string, SourceDeliveryNoteLine>, array<string, string>} the note lines of the company, and
     *                                                                             what each leaves to this invoice
     */
    private function room(Company $company, Invoice $invoice, array $ids, bool $held = false): array
    {
        if ([] === $ids) {
            return [[], []];
        }
        $sources = $held ? $this->sourceLines->lockedOfIds($ids, $company->getId()) : $this->sourceLines->ofIds($ids, $company->getId());
        $elsewhere = $this->invoices->invoicedQuantities($company->getId(), $ids, false, $invoice->getId());
        $room = [];
        foreach ($sources as $id => $source) {
            $room[$id] = Decimal::format(Decimal::of($source->quantity)->sub(Decimal::of($elsewhere[$id] ?? '0')), 3);
        }

        return [$sources, $room];
    }

    /** @param array{products: list<string>, units: list<string>, taxes: list<string>, documentTaxes: list<string>, sources: list<string>} $kept */
    private function line(Company $company, Customer $customer, InvoiceLineInput $line, array $kept): InvoiceLineDetails
    {
        if (null !== $line->sourceDeliveryNoteLineId && !\in_array($line->sourceDeliveryNoteLineId->toRfc4122(), $kept['sources'], true)) {
            throw new InvalidInvoice('sourceDeliveryNoteLineId', 'A line invoices a delivery note line only when its draft already did: an invoice of delivery notes is drafted from them.');
        }

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

        // A product line sent without a price starts where the screen would have started it: the customer's list price.
        $price = $line->unitPriceNet
            ?? (null === $product ? null : $this->linePrices->startingPrice($product, $customer->getId(), $line->quantity))
            ?? throw new InvalidInvoice('unitPriceNet', 'A line without a product states its price.');
        $description = null === $line->description || '' === trim($line->description) ? ($product?->getDetails()->name ?? '') : $line->description;

        return new InvoiceLineDetails($product, $description, $line->quantity, $unit, $price, $line->discountRate, $this->lineTaxes($company, $customer, $line, $product?->getDefaultTaxComponentIds(), $kept['taxes']), $line->sourceDeliveryNoteLineId, $line->lotCode, $line->returned, discountAmount: $line->discountAmount, section: $line->section);
    }

    /**
     * The taxes a line states, each checked; or, when it states none, its product's default line taxes (a line with no
     * product, the company's) that its customer's regime charges and the company still has active.
     *
     * @param list<string>|null $productDefaults
     * @param list<string>      $kept
     *
     * @return list<TaxComponent>
     */
    private function lineTaxes(Company $company, Customer $customer, InvoiceLineInput $line, ?array $productDefaults, array $kept): array
    {
        if (null === $line->taxComponentIds) {
            return $this->chargeable($company, $customer, $productDefaults ?? $this->defaultLineTaxIds($company), TaxKind::PercentageLine);
        }

        return $this->stated($company, $customer, $line->taxComponentIds, $kept, 'taxComponentIds');
    }

    /**
     * What a line naming no product is charged when it states no taxes: the company's default percentage taxes, the VAT
     * a counter sale would carry, which the customer's regime then still filters. A line that states none at all, an
     * empty list, keeps meaning no tax.
     *
     * @return list<string>
     */
    private function defaultLineTaxIds(Company $company): array
    {
        return array_values(array_map(
            static fn (TaxComponent $tax): string => $tax->getId()->toRfc4122(),
            array_filter($this->taxes->ofCompany($company->getId()), static fn (TaxComponent $tax): bool => $tax->isDefault() && TaxKind::PercentageLine === $tax->getKind()),
        ));
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
     * The active taxes among the ids that the customer's regime and the company's own charge, of one kind when one is named.
     *
     * @param list<string> $ids
     *
     * @return list<TaxComponent>
     */
    private function chargeable(Company $company, Customer $customer, array $ids, ?TaxKind $kind): array
    {
        $excluded = $this->excluded->of($company, $customer->getTaxRegime());
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
            $leftOutBy = $this->excluded->regimeLeavingOut($company, $customer->getTaxRegime(), $tax->getFamily());
            if (null !== $leftOutBy) {
                throw new InvalidInvoice($field, \sprintf('The %s regime does not charge %s.', $leftOutBy, $tax->getCode()));
            }
            $taxes[] = $tax;
        }

        return $taxes;
    }

    /**
     * The products, units, line taxes, document taxes and delivery note lines a draft already names, by id.
     *
     * @return array{products: list<string>, units: list<string>, taxes: list<string>, documentTaxes: list<string>, sources: list<string>}
     */
    private static function named(?Invoice $invoice): array
    {
        $named = ['products' => [], 'units' => [], 'taxes' => [], 'documentTaxes' => [], 'sources' => []];
        foreach ($invoice?->getLines() ?? [] as $line) {
            $product = $line->getProduct();
            if (null !== $product) {
                $named['products'][] = $product->getId()->toRfc4122();
            }
            $named['units'][] = $line->getUnit()->getId()->toRfc4122();
            $source = $line->getSourceDeliveryNoteLineId();
            if (null !== $source) {
                $named['sources'][] = $source->toRfc4122();
            }
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
