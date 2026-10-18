<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Module\Purchases\Domain\ValueObject\VendorSnapshot;

/**
 * Who a vendor is today, as an order keeps it once sent and a draft prints it. Here rather than on OrderVendor, which
 * the vendors' module builds and so stays plain data (ModuleBoundariesTest).
 */
final class VendorCard
{
    public static function of(OrderVendor $vendor): VendorSnapshot
    {
        return new VendorSnapshot($vendor->number, $vendor->name, $vendor->legalName, $vendor->identifiers, $vendor->line1, $vendor->line2, $vendor->postalCode, $vendor->city, $vendor->countryCode, $vendor->email, $vendor->phone);
    }
}
