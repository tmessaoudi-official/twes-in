<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Export;

use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\Vendors\Application\ExpenseCategoryDirectory;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorSearch;
use App\Module\Vendors\Infrastructure\ApiPlatform\VendorPermission;
use App\Module\Vendors\Infrastructure\ApiPlatform\VendorSearchReader;
use App\Module\Vendors\Infrastructure\Import\VendorImport;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;

/**
 * The vendors list as a file, under the columns the import reads (docs/SPEC.md § 7, row 60), so what a company exports
 * it can correct in a spreadsheet and bring back. The vendor's usual expense category is written by its name, which is
 * what the import reads, archived or not: the import keeps a vendor's own category under its name.
 */
final readonly class VendorExport implements DeclaresExport
{
    private const int BATCH = 200;

    public function __construct(private VendorImport $columns, private ManageVendors $manage, private ExpenseCategoryDirectory $expenseCategories)
    {
    }

    public function key(): string
    {
        return VendorImport::KEY;
    }

    public function permission(): string
    {
        return VendorPermission::READ;
    }

    public function module(): string
    {
        return VendorsModule::KEY;
    }

    public function columns(Company $company): array
    {
        return $this->columns->subjectFor($company)->keys();
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $search = VendorSearchReader::read($query->parameters(), $query->text(), $query->order(VendorSearch::SORTS), $company);
        $columns = $this->columns($company);
        $categories = array_map(static fn (array $category): string => $category['name'], $this->expenseCategories->categoriesInCompany($company->getId()));

        for ($page = 1;; ++$page) {
            $answer = $this->manage->search($company, $search, new PageRequest($page, self::BATCH));
            foreach ($answer->items as $vendor) {
                yield array_map(static fn (string $column): string => self::cell($vendor, $column, $categories), $columns);
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }

    /** @param array<string, string> $categories */
    private static function cell(Vendor $vendor, string $column, array $categories): string
    {
        $profile = $vendor->getProfile();
        $address = $profile->address;

        return match ($column) {
            'number' => $vendor->getNumber(),
            'name' => $profile->name,
            'legal_name' => $profile->legalName ?? '',
            'email' => $profile->email ?? '',
            'phone' => $profile->phone ?? '',
            'website' => $profile->website ?? '',
            'line1' => $address->line1 ?? '',
            'line2' => $address->line2 ?? '',
            'postal_code' => $address->postalCode ?? '',
            'city' => $address->city ?? '',
            'country_code' => $address->countryCode ?? '',
            'iban' => $profile->iban ?? '',
            'bic' => $profile->bic ?? '',
            'payment_terms_days' => null === $profile->paymentTermsDays ? '' : (string) $profile->paymentTermsDays,
            'expense_category' => null === $profile->defaultExpenseCategoryId ? '' : ($categories[$profile->defaultExpenseCategoryId->toRfc4122()] ?? ''),
            'notes' => $profile->notes ?? '',
            'active' => $vendor->isActive() ? 'yes' : 'no',
            default => $profile->identifiers[$column] ?? '',
        };
    }
}
