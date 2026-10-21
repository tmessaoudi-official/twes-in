<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Import;

use App\Module\Customers\Domain\Model\Customer;
use App\Shared\Domain\Model\PostalAddress;

/**
 * A customer as a file writes it, column by column, the way the import reads it back: what the export writes, and
 * what an upsert compares before and after it writes a row (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 5).
 */
final class CustomerInFile
{
    /** @param array<string, string> $taxCodes by tax id */
    public static function cell(Customer $customer, string $column, array $taxCodes): string
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
            'to_check' === $column => $customer->getCheckMark()->note ?? '',
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
