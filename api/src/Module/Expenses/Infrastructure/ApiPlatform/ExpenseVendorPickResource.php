<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The vendors the expense form offers while a person types (docs/SPEC.md § 7, 2026-09-17, ruling 3). The same shape
 * `ExpenseOptionsResource::$vendors` carried, asked for a few at a time instead of whole.
 *
 * It sits under the EXPENSE's permission, not the vendor book's: recording an expense is enough to name who it was
 * paid to, and asking to read the whole book would be asking for more than the form needs. That is why each form has
 * a picker of its own rather than one shared "vendors" endpoint — the shape and the permission both belong to the
 * form asking.
 */
#[ApiResource(
    shortName: 'ExpenseVendorPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/expense-options/vendors',
            name: self::PICK,
            provider: ExpenseVendorPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 100],
                    description: 'Words found in the number, name, email, address or registration numbers, whatever their case and accents; under three characters, the exact number only. Left out, the first few by number.',
                ),
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 20],
                    description: 'Resolves vendors an expense already names, rather than searching: what a form needs to show what it holds. Unlike a search, this answers a vendor that has since been retired. Given, `q` is ignored.',
                ),
            ],
        ),
    ],
)]
final class ExpenseVendorPickResource
{
    public const string READ = 'expense_vendor_pick:read';
    public const string PICK = 'expense_vendor_pick';

    // No identifier: reached only through the uriTemplate above.
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $number = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    /** How many days after its date this vendor's invoices are usually due, when it says. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public ?int $paymentTermsDays = null;

    /** The category this vendor's expenses usually go to, so a line starts where it belongs. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public ?string $defaultExpenseCategoryId = null;

    /** @param array{id: string, number: string, name: string, paymentTermsDays: int|null, defaultExpenseCategoryId: string|null} $pick */
    public static function of(array $pick): self
    {
        $resource = new self();
        $resource->id = $pick['id'];
        $resource->number = $pick['number'];
        $resource->name = $pick['name'];
        $resource->paymentTermsDays = $pick['paymentTermsDays'];
        $resource->defaultExpenseCategoryId = $pick['defaultExpenseCategoryId'];

        return $resource;
    }
}
