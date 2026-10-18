<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Purchases;

use App\Module\Purchases\Application\OrderVendor;
use App\Module\Purchases\Application\OrderVendors;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\Repository\VendorRepository;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** Answers the purchases module's `OrderVendors` port out of this module. */
final readonly class VendorsForOrders implements OrderVendors
{
    /** As many as a picker shows, the same as every other picker. */
    private const int SHOWN = 20;

    public function __construct(private VendorRepository $vendors)
    {
    }

    public function ofId(Company $company, Uuid $id): ?OrderVendor
    {
        $vendor = $this->vendors->ofIdInCompany($id, $company->getId());

        return null === $vendor ? null : self::vendor($vendor);
    }

    public function matching(Company $company, string $words): array
    {
        return array_map(self::vendor(...), $this->vendors->pick($company->getId(), $words, self::SHOWN));
    }

    public function ofIds(Company $company, array $ids): array
    {
        return array_map(self::vendor(...), $this->vendors->ofIdsInCompany($ids, $company->getId()));
    }

    private static function vendor(Vendor $vendor): OrderVendor
    {
        $profile = $vendor->getProfile();
        $address = $profile->address;

        return new OrderVendor(
            $vendor->getId(),
            $vendor->getNumber(),
            $profile->name,
            $vendor->isActive(),
            $profile->legalName,
            $profile->identifiers,
            $address->line1,
            $address->line2,
            $address->postalCode,
            $address->city,
            $address->countryCode,
            $profile->email,
            $profile->phone,
        );
    }
}
