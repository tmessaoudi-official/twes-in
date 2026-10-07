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
use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Shared\Domain\PageRequest;
use App\Shared\Domain\PostalAddress;
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
            foreach ($answer->items as $customer) {
                yield array_map(fn (string $column): string => $this->cell($customer, $column, $codes), $columns);
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }

    /** @param array<string, string> $taxCodes by tax id */
    private function cell(Customer $customer, string $column, array $taxCodes): string
    {
        $profile = $customer->getProfile();
        $billing = $profile->billingAddress;
        $shipping = $profile->shippingAddress ?? new PostalAddress();

        return match (true) {
            'number' === $column => $customer->getNumber(),
            'kind' === $column => $profile->kind->value,
            'name' === $column => $profile->name,
            'legal_name' === $column => $profile->legalName ?? '',
            'email' === $column => $profile->email ?? '',
            'phone' === $column => $profile->phone ?? '',
            'website' === $column => $profile->website ?? '',
            str_starts_with($column, 'billing_') => self::address($billing, substr($column, 8)),
            str_starts_with($column, 'shipping_') => self::address($shipping, substr($column, 9)),
            'customer_group' === $column => $customer->getGroup()?->getName() ?? '',
            'tax_regime_code' === $column => $customer->getTaxRegime()->getCode(),
            'default_tax_codes' === $column => implode(',', array_map(static fn (string $id): string => $taxCodes[$id] ?? '', $customer->getDefaultTaxComponentIds())),
            'default_discount_rate' === $column => $profile->defaultDiscountRate ?? '',
            'notes' === $column => $profile->notes ?? '',
            'active' === $column => $customer->isActive() ? 'yes' : 'no',
            str_starts_with($column, CustomerImport::CUSTOM_PREFIX) => self::custom($customer->getCustomFields()[substr($column, \strlen(CustomerImport::CUSTOM_PREFIX))] ?? null),
            default => $profile->identifiers[$column] ?? '',
        };
    }

    private static function address(PostalAddress $address, string $part): string
    {
        return match ($part) {
            'line1' => $address->line1 ?? '',
            'line2' => $address->line2 ?? '',
            'postal_code' => $address->postalCode ?? '',
            'city' => $address->city ?? '',
            'country_code' => $address->countryCode ?? '',
            default => '',
        };
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
