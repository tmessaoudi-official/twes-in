<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Import;

use App\Module\Vendors\Domain\Model\Vendor;

/**
 * A vendor as a file writes it, column by column, the way the import reads it back: what the export writes, and what
 * an upsert compares before and after it writes a row (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 5).
 */
final class VendorInFile
{
    /** @param array<string, string> $categories */
    public static function cell(Vendor $vendor, string $column, array $categories): string
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
