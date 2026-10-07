<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Export;

use App\Fiscal\Domain\TaxComponentRepository;
use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Domain\BarcodeRole;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductSearch;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Module\Products\Infrastructure\ApiPlatform\ProductSearchReader;
use App\Module\Products\Infrastructure\Import\ProductImport;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;

/**
 * The products list as a file, under the columns the import reads (docs/SPEC.md § 7, row 60), so what a company exports
 * it can correct in a spreadsheet and bring back. The import offers `cost_price` only to whoever may read costs
 * (`product.cost.read`), and the file takes its columns from it, so a reader without that permission is given no cost
 * column at all rather than an empty one. Where a product lies and its reorder point are left to the stock list: they
 * are the inventory's to say, and an import reads a file that does not carry them. Only the unit barcode goes out: a
 * supplier's own codes are costs' neighbours and stay behind the same permission.
 */
final readonly class ProductExport implements DeclaresExport
{
    private const int BATCH = 200;
    /** Columns the inventory answers, which this module cannot read without asking it for each product. */
    private const array STOCK_COLUMNS = ['home_location', 'reorder_point'];

    public function __construct(
        private ProductImport $columns,
        private ManageProducts $manage,
        private TaxComponentRepository $taxes,
    ) {
    }

    public function key(): string
    {
        return ProductImport::KEY;
    }

    public function permission(): string
    {
        return ProductPermission::READ;
    }

    public function module(): string
    {
        return ProductsModule::KEY;
    }

    public function columns(Company $company): array
    {
        return array_values(array_diff($this->columns->subjectFor($company)->keys(), self::STOCK_COLUMNS));
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $search = ProductSearchReader::read($query->parameters(), $query->text(), $query->order(ProductSearch::SORTS));
        $columns = $this->columns($company);
        $codes = [];
        foreach ($this->taxes->ofCompany($company->getId()) as $tax) {
            $codes[$tax->getId()->toRfc4122()] = $tax->getCode();
        }

        for ($page = 1;; ++$page) {
            $answer = $this->manage->search($company, $search, new PageRequest($page, self::BATCH));
            foreach ($answer->items as $product) {
                yield array_map(fn (string $column): string => $this->cell($product, $column, $codes), $columns);
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }

    /** @param array<string, string> $taxCodes by tax id */
    private function cell(Product $product, string $column, array $taxCodes): string
    {
        $details = $product->getDetails();

        return match (true) {
            'reference' === $column => $product->getReference(),
            'name' === $column => $details->name,
            'kind' === $column => $details->kind->value,
            'description' === $column => $details->description ?? '',
            'unit_code' === $column => $product->getUnit()->getCode(),
            'category' === $column => $product->getCategory()?->getName() ?? '',
            'unit_price_net' === $column => $details->unitPriceNet,
            'cost_price' === $column => $details->costPrice ?? '',
            'barcode' === $column => self::unitBarcode($product),
            'default_tax_codes' === $column => implode(',', array_map(static fn (string $id): string => $taxCodes[$id] ?? '', $product->getDefaultTaxComponentIds())),
            'active' === $column => $product->isActive() ? 'yes' : 'no',
            str_starts_with($column, ProductImport::CUSTOM_PREFIX) => self::custom($product->getCustomFields()[substr($column, \strlen(ProductImport::CUSTOM_PREFIX))] ?? null),
            default => '',
        };
    }

    private static function unitBarcode(Product $product): string
    {
        foreach ($product->getBarcodes() as $barcode) {
            if (BarcodeRole::Unit === $barcode->getRole()) {
                return $barcode->getCode();
            }
        }

        return '';
    }

    private static function custom(string|int|float|bool|null $value): string
    {
        return match (true) {
            null === $value => '',
            \is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
