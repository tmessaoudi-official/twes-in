<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\ImportExport\Application\DeclaresImport;
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
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\ProductBarcodeTaken;
use App\Module\Products\Domain\Exception\InvalidProduct;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Who each product is bought from, from a file: a row names a product by its reference and a vendor by its number,
 * with the vendor's own code for the product when there is one. A product bought from several vendors has a row for
 * each. Written through ManageProducts::addVendor, the product's own rules: a file adds links and codes, never removes
 * one, as the product file's supplier columns do.
 *
 * Who a product is bought from is read with costs, so the subject exists only for somebody who may read them, and only
 * while the company keeps vendors: anybody else is answered as if nothing of the name could be imported.
 */
final readonly class ProductVendorImport implements DeclaresImport
{
    public const string KEY = 'product-vendors';

    public function __construct(
        private ManageProducts $manage,
        private ProductRepository $products,
        private VendorRepository $vendors,
        private CompanyGuard $guard,
        private ModuleStates $modules,
        private EntityManagerInterface $entityManager,
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

    public function identityColumns(): array
    {
        return ['product_reference', 'vendor_number'];
    }

    public function identityOf(Company $company, ImportRecord $record): ?RowIdentity
    {
        return RowIdentity::ofColumns($record, $this->identityColumns());
    }

    public function subjectFor(Company $company): ImportSubject
    {
        if (!$this->guard->may($company, ProductPermission::COST_READ) || !$this->modules->isEnabled($company->getId(), VendorsModule::KEY)) {
            throw new UnknownImportSubject(\sprintf('Nothing named "%s" can be imported.', self::KEY));
        }

        return new ImportSubject(self::KEY, [
            new ImportColumn('product_reference', 'import.product_vendors.product_reference', true, 'VIS-6X40', 'import.product_vendors.product_reference_note', code: true),
            new ImportColumn('vendor_number', 'import.product_vendors.vendor_number', true, 'FRN-0001', 'import.product_vendors.vendor_number_note', code: true),
            new ImportColumn('vendor_code', 'import.product_vendors.vendor_code', false, 'SF-883', 'import.product_vendors.vendor_code_note', code: true),
        ]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId, RowNotes $notes, ImportContext $context): RowImported
    {
        // Every cell is read before anything is written, and every fault found is said at once.
        $faults = new RowFaults();
        $product = $faults->read(fn (): Product => $this->productOf($company, $record));
        $vendor = $faults->read(fn (): Vendor => $this->vendorOf($company, $record));
        $faults->stopIfAny();
        \assert(null !== $product && null !== $vendor);

        try {
            $linked = array_any($product->getVendors(), static fn (Vendor $held): bool => $held->getId()->equals($vendor->getId()));
            if ($linked && ImportMode::Create === $mode) {
                throw new RowRejected('vendor_number', 'This product is already bought from this vendor. Import in "create and update" mode to add a code of theirs.', 'already_exists');
            }
            $changed = $this->manage->addVendor($company, $product->getId(), $vendor->getId(), $record->value('vendor_code'), $actorUserId);

            return match (true) {
                !$linked => RowImported::Created,
                [] !== $changed => RowImported::Updated,
                default => RowImported::Unchanged,
            };
        } catch (ProductBarcodeTaken $taken) {
            throw new RowRejected('vendor_code', $taken->getMessage(), 'barcode_taken', ['reference' => $taken->heldBy]);
        } catch (InvalidProduct $refused) {
            throw new RowRejected(str_starts_with($refused->field, 'barcodes.') ? 'vendor_code' : 'vendor_number', $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params);
        } finally {
            // Doctrine's batch processing: a row's product leaves the unit of work once written, or a file costs the
            // square of its length; the next row reads it again through the repository.
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

        return $this->products->ofReferenceInCompany($reference, $company->getId())
            ?? throw new RowRejected('product_reference', \sprintf('The company has no product referenced "%s". Import the products first.', $reference), 'unknown_product', ['reference' => $reference]);
    }

    /** @throws RowRejected */
    private function vendorOf(Company $company, ImportRecord $record): Vendor
    {
        $number = $record->value('vendor_number')
            ?? throw new RowRejected('vendor_number', 'Every row names a vendor by its number.', 'value_required');

        return $this->vendors->ofNumberInCompany($number, $company->getId())
            ?? throw new RowRejected('vendor_number', \sprintf('The company has no supplier numbered "%s".', $number), 'unknown_vendor', ['number' => $number]);
    }
}
