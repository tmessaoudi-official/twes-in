<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportCells;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportContext;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\RowFaults;
use App\ImportExport\Application\RowIdentity;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowNotes;
use App\ImportExport\Application\RowRejected;
use App\ImportExport\Application\UnknownImportSubject;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductCostChange;
use App\Module\Products\Domain\Repository\ProductCostChangeRepository;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A shop's past purchases, from a file: each row a line it bought, kept in its product's cost history with the vendor
 * it came from, its day, its invoice, how many and what one cost. History only: the product's cost, its price and its
 * stock are not moved, so the file can come after the products and their vendors and change nothing they hold.
 *
 * A day no calendar holds, or one before any shop's books or after today, is kept as no day and said so, rather than
 * refusing a line whose cost is right: no day is invented. Two identical lines are two lines, as a sheet may buy the
 * same thing twice on one invoice, so a row is found by nothing and the file only ever adds.
 *
 * What a product was bought for is a cost, read only with `product.cost.read`: whoever lacks it is offered no such file.
 */
final readonly class PurchaseHistoryImport implements DeclaresImport
{
    public const string KEY = 'purchase-history';

    /** No shop's books here start before this day: an earlier one is a typing slip (« 0202 » for 2022). */
    private const string EARLIEST = '1990-01-01';

    public function __construct(
        private ProductRepository $products,
        private VendorRepository $vendors,
        private ProductCostChangeRepository $history,
        private CompanyGuard $guard,
        private ModuleStates $modules,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function permission(): string
    {
        return ProductPermission::WRITE;
    }

    public function module(): string
    {
        return ProductsModule::KEY;
    }

    /** What a row names; it is never found again by them, as two lines alike are two purchases (no identity below). */
    public function identityColumns(): array
    {
        return ['product_reference', 'vendor_number'];
    }

    public function identityOf(Company $company, ImportRecord $record): ?RowIdentity
    {
        return null;
    }

    public function subjectFor(Company $company): ImportSubject
    {
        if (!$this->modules->isEnabled($company->getId(), VendorsModule::KEY) || !$this->guard->may($company, ProductPermission::COST_READ)) {
            throw new UnknownImportSubject(\sprintf('Nothing named "%s" can be imported.', self::KEY));
        }

        return new ImportSubject(self::KEY, [
            new ImportColumn('product_reference', 'import.purchase_history.product_reference', true, 'VIS-6X40', 'import.purchase_history.product_reference_note', code: true),
            new ImportColumn('vendor_number', 'import.purchase_history.vendor_number', true, 'FRN-0001', 'import.purchase_history.vendor_number_note', code: true),
            new ImportColumn('date', 'import.purchase_history.date', false, '2025-06-10', 'import.purchase_history.date_note'),
            new ImportColumn('invoice_number', 'import.purchase_history.invoice_number', false, 'FA-2025-118', 'import.purchase_history.invoice_number_note', code: true),
            new ImportColumn('quantity', 'import.purchase_history.quantity', false, '72', 'import.purchase_history.quantity_note'),
            new ImportColumn('unit_cost', 'import.purchase_history.unit_cost', true, '1.6882', 'import.purchase_history.unit_cost_note'),
        ]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId, RowNotes $notes, ImportContext $context): RowImported
    {
        // Every cell is read before anything is written, and every fault found is said at once.
        $faults = new RowFaults();
        $product = $faults->read(fn (): Product => $this->productOf($company, $record));
        $named = $faults->read(fn (): Vendor => $this->vendorOf($company, $record));
        $cost = $faults->read(static fn (): string => self::costOf($record));
        $quantity = $faults->read(static fn (): ?string => self::quantityOf($record));
        $invoice = $faults->read(static fn (): ?string => self::invoiceOf($record));
        $faults->stopIfAny();
        \assert(null !== $product && null !== $named && null !== $cost);
        $vendor = MergedVendors::followed($named, 'vendor_number', $notes);
        $day = $this->dayOf($company, $record, $notes);

        try {
            $line = ProductCostChange::purchased($product, $vendor, $day, $invoice, $quantity, $cost, $context->runId, $actorUserId, $this->clock->now());
            $this->history->save($line);
            $context->created('product_cost_change', $line->getId());

            return RowImported::Created;
        } finally {
            // Doctrine's batch processing: a row's product leaves the unit of work once written, as every product file does.
            if ($this->entityManager->contains($product)) {
                $this->entityManager->detach($product);
            }
        }
    }

    public function finished(Company $company, ImportContext $context, ?Uuid $actorUserId): void
    {
    }

    /** @throws RowRejected */
    private function productOf(Company $company, ImportRecord $record): Product
    {
        $reference = $record->value('product_reference')
            ?? throw new RowRejected('product_reference', 'Every row names a product by its reference.', 'value_required');

        $product = $this->products->ofReferenceInCompany($reference, $company->getId())
            ?? throw new RowRejected('product_reference', \sprintf('The company has no product referenced "%s". Import the products first.', $reference), 'unknown_product', ['reference' => $reference]);

        // A product merged away was bought as the product kept, whose history it is now.
        return $product->standingFor();
    }

    /** @throws RowRejected */
    private function vendorOf(Company $company, ImportRecord $record): Vendor
    {
        $number = $record->value('vendor_number')
            ?? throw new RowRejected('vendor_number', 'Every row names a vendor by its number.', 'value_required');

        return $this->vendors->ofNumberInCompany($number, $company->getId())
            ?? throw new RowRejected('vendor_number', \sprintf('The company has no supplier numbered "%s".', $number), 'unknown_vendor', ['number' => $number]);
    }

    /**
     * @return numeric-string
     *
     * @throws RowRejected
     */
    private static function costOf(ImportRecord $record): string
    {
        $cost = ImportCells::decimal($record, 'unit_cost', 4)
            ?? throw new RowRejected('unit_cost', 'Every row says what one unit cost.', 'value_required');
        if (1 !== preg_match('/^(0|[1-9][0-9]{0,9})(\.[0-9]+)?$/', $cost) || !is_numeric($cost)) {
            throw new RowRejected('unit_cost', 'A cost is a number from 0.', 'invalid_price');
        }

        return $cost;
    }

    /**
     * @return numeric-string|null
     *
     * @throws RowRejected
     */
    private static function quantityOf(ImportRecord $record): ?string
    {
        $quantity = ImportCells::decimal($record, 'quantity', 3);
        if (null === $quantity) {
            return null;
        }
        // A return is a line bought back below zero, kept as written, as a line of 0 is: history keeps what the sheet says.
        if (1 !== preg_match('/^-?(0|[1-9][0-9]{0,10})(\.[0-9]+)?$/', $quantity) || !is_numeric($quantity)) {
            throw new RowRejected('quantity', 'A quantity is a number of at most eleven digits; a return is below 0.', 'invalid_quantity');
        }

        return $quantity;
    }

    /** @throws RowRejected */
    private static function invoiceOf(ImportRecord $record): ?string
    {
        $invoice = $record->value('invoice_number');
        if (null !== $invoice && mb_strlen($invoice) > 64) {
            throw new RowRejected('invoice_number', 'An invoice number is at most 64 characters.', 'invalid_text', ['max' => 64]);
        }

        return $invoice;
    }

    /** The day written, day first or as a date field keeps it; one that cannot be is kept as no day, and the row says so. */
    private function dayOf(Company $company, ImportRecord $record, RowNotes $notes): ?\DateTimeImmutable
    {
        $cell = $record->value('date');
        if (null === $cell) {
            return null;
        }
        $written = TypedText::trimmed(ImportCells::day($cell));
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $written, new \DateTimeZone($company->getTimezone()));
        $today = $this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d');
        // A day that rolls over (2025-13-45) comes back as another: only one read back as written is a day.
        if (false === $day || $day->format('Y-m-d') !== $written || $written < self::EARLIEST || $written > $today) {
            $notes->note('date', 'date_kept_unknown', ['date' => $cell]);

            return null;
        }

        return $day;
    }
}
