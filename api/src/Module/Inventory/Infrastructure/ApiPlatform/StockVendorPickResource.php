<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The vendors the goods receipt form offers while a person types: a few at a time, never the whole book.
 *
 * It sits under the STOCK write permission, not the vendor book's: receiving goods is enough to say who they came
 * from, and asking to read the book would be asking for more than the form needs. Each form has a picker of its own
 * for that reason, as the expense form has (the shape and the permission both belong to the form asking).
 */
#[ApiResource(
    shortName: 'StockVendorPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-options/vendors',
            name: self::PICK,
            provider: StockVendorPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 100],
                    description: 'Words found in the number, name, email, address or registration numbers, whatever their case and accents; under three characters, the exact number only. Left out, the first few by number.',
                ),
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 20],
                    description: 'Resolves vendors a receipt already names, retired or not, rather than searching. Given, `q` is ignored.',
                ),
            ],
        ),
    ],
)]
final class StockVendorPickResource
{
    public const string READ = 'stock_vendor_pick:read';
    public const string PICK = 'stock_vendor_pick';

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

    /** @param array{id: string, number: string, name: string, paymentTermsDays: int|null, defaultExpenseCategoryId: string|null} $pick */
    public static function of(array $pick): self
    {
        $resource = new self();
        $resource->id = $pick['id'];
        $resource->number = $pick['number'];
        $resource->name = $pick['name'];

        return $resource;
    }
}
