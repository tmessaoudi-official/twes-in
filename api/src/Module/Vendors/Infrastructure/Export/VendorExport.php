<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Export;

use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
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
 * it can correct in a spreadsheet and bring back. The vendor's usual expense category is left out: the expenses module
 * names it, a vendor only holds its id, and an import keeps what a vendor has when that column is absent.
 */
final readonly class VendorExport implements DeclaresExport
{
    private const int BATCH = 200;
    private const array OMITTED = ['expense_category'];

    public function __construct(private VendorImport $columns, private ManageVendors $manage)
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
        return array_values(array_diff($this->columns->subjectFor($company)->keys(), self::OMITTED));
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $search = VendorSearchReader::read($query->parameters(), $query->text(), $query->order(VendorSearch::SORTS), $company);
        $columns = $this->columns($company);

        for ($page = 1;; ++$page) {
            $answer = $this->manage->search($company, $search, new PageRequest($page, self::BATCH));
            foreach ($answer->items as $vendor) {
                yield array_map(static fn (string $column): string => self::cell($vendor, $column), $columns);
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }

    private static function cell(Vendor $vendor, string $column): string
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
            'notes' => $profile->notes ?? '',
            'active' => $vendor->isActive() ? 'yes' : 'no',
            default => $profile->identifiers[$column] ?? '',
        };
    }
}
