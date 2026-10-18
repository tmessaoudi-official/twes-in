<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Purchases\Application\OrderVendor;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider\PurchaseOrderVendorPickProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The vendors the order form and the orders list offer while a person types, under the orders' own permission, a few
 * at a time: no total and no page.
 */
#[ApiResource(
    shortName: 'PurchaseOrderVendorPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/purchase-order-options/vendors',
            name: self::PICK,
            provider: PurchaseOrderVendorPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 100],
                    description: 'Words found in the vendor\'s number or name, whatever their case and accents. Left out, the first few active vendors.',
                ),
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 20],
                    description: 'Resolves vendors an order or a filter already names, retired ones too. Given, `q` is ignored.',
                ),
            ],
        ),
    ],
)]
final class PurchaseOrderVendorPickResource
{
    public const string READ = 'purchase_order_vendor_pick:read';
    public const string PICK = 'purchase_order_vendor_pick';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $number = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    /** Whether a new order may still name it. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public bool $active = true;

    public static function of(OrderVendor $vendor): self
    {
        $resource = new self();
        $resource->id = $vendor->id->toRfc4122();
        $resource->number = $vendor->number;
        $resource->name = $vendor->name;
        $resource->active = $vendor->active;

        return $resource;
    }
}
