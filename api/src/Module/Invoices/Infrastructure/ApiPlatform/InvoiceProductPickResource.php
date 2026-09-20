<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The products the invoice form offers while a person types a line (docs/SPEC.md § 7, 2026-09-17, ruling 3). The same
 * shape `InvoiceOptionsResource::$products` carried, asked for a few at a time: a catalogue of twenty thousand is a
 * payload nobody waits for and a dropdown nobody scrolls.
 *
 * Same placement rule as the customer picker beside it: the invoice's own permission here, the search in the Products
 * module (`PickProducts`), no total and no page.
 */
#[ApiResource(
    shortName: 'InvoiceProductPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/invoice-options/products',
            name: self::PICK,
            provider: InvoiceProductPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 100],
                    description: 'Words found in the reference, name or barcode, whatever their case and accents; under three characters, the exact reference only. Left out, the first few by reference.',
                ),
            ],
        ),
    ],
)]
final class InvoiceProductPickResource
{
    public const string READ = 'invoice_product_pick:read';
    public const string PICK = 'invoice_product_pick';

    // No identifier: reached only through the uriTemplate above.
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $reference = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    /** The unit the product is sold in, which a line's quantity is counted in. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $unitId = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $unitPriceNet = '';

    /** @var list<string> */
    #[ApiProperty(required: true, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public array $defaultTaxComponentIds = [];

    /** @param array{id: string, reference: string, name: string, unitId: string, unitPriceNet: string, defaultTaxComponentIds: list<string>} $pick */
    public static function of(array $pick): self
    {
        $resource = new self();
        $resource->id = $pick['id'];
        $resource->reference = $pick['reference'];
        $resource->name = $pick['name'];
        $resource->unitId = $pick['unitId'];
        $resource->unitPriceNet = $pick['unitPriceNet'];
        $resource->defaultTaxComponentIds = $pick['defaultTaxComponentIds'];

        return $resource;
    }
}
