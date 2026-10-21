<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Products;

use App\Module\Products\Application\Vendors\ProductVendorPick;
use App\Module\Products\Application\Vendors\ProductVendorPicks;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\Module\Vendors\Infrastructure\Module\VendorsModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Model\Company;

/** Answers the products module's `ProductVendorPicks` port out of this module. */
final readonly class VendorsForProducts implements ProductVendorPicks
{
    /** As many as a picker shows, the same as every other picker. */
    private const int SHOWN = 20;

    public function __construct(private VendorRepository $vendors, private ModuleStates $modules)
    {
    }

    public function available(Company $company): bool
    {
        return $this->modules->isEnabled($company->getId(), VendorsModule::KEY);
    }

    public function matching(Company $company, string $words): array
    {
        return array_map(self::pick(...), $this->vendors->pick($company->getId(), $words, self::SHOWN));
    }

    public function ofIds(Company $company, array $ids): array
    {
        return array_map(self::pick(...), $this->vendors->ofIdsInCompany($ids, $company->getId()));
    }

    private static function pick(Vendor $vendor): ProductVendorPick
    {
        return new ProductVendorPick($vendor->getId(), $vendor->getNumber(), $vendor->getProfile()->name, $vendor->isActive());
    }
}
