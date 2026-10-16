<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Export;

use App\Fiscal\Application\Regime\ListCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxComponentRepository;
use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerSearch;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerSearchReader;
use App\Module\Customers\Infrastructure\Import\CustomerImport;
use App\Module\Customers\Infrastructure\Import\CustomerInFile;
use App\Module\Customers\Infrastructure\Import\CustomerSettingCells;
use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;

/**
 * The customers list as a file, under the columns the import reads (docs/SPEC.md § 7, row 60), so what a company
 * exports it can correct in a spreadsheet and bring back. The search, the filters and the order are the list's own,
 * read by the list's own reader.
 */
final readonly class CustomerExport implements DeclaresExport
{
    private const int BATCH = 200;

    public function __construct(
        private CustomerImport $columns,
        private ManageCustomers $manage,
        private TaxComponentRepository $taxes,
        private ListCustomerTaxRegimes $regimes,
        private CustomerSettingCells $settings,
    ) {
    }

    public function key(): string
    {
        return CustomerImport::KEY;
    }

    public function permission(): string
    {
        return CustomerPermission::READ;
    }

    public function module(): string
    {
        return CustomersModule::KEY;
    }

    public function columns(Company $company): array
    {
        return $this->columns->subjectFor($company)->keys();
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $regimes = array_map(static fn (CustomerTaxRegime $regime): string => $regime->getCode(), $this->regimes->for($company));
        $search = CustomerSearchReader::read($query->parameters(), $query->text(), $query->order(CustomerSearch::SORTS), $company, $regimes);
        $columns = $this->columns($company);
        $codes = [];
        foreach ($this->taxes->ofCompany($company->getId()) as $tax) {
            $codes[$tax->getId()->toRfc4122()] = $tax->getCode();
        }

        for ($page = 1;; ++$page) {
            $answer = $this->manage->search($company, $search, new PageRequest($page, self::BATCH));
            $settings = $this->settings->shown($company, array_map(static fn (Customer $customer) => $customer->getId(), $answer->items));
            foreach ($answer->items as $customer) {
                $own = $settings[$customer->getId()->toRfc4122()] ?? [];
                yield array_map(static fn (string $column): string => CustomerSettingCells::covers($column) ? $own[$column] ?? '' : CustomerInFile::cell($customer, $column, $codes), $columns);
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }
}
