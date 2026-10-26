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
use App\Module\Purchases\Application\Receivers\ReceivingMember;
use App\Module\Purchases\Infrastructure\ApiPlatform\State\Provider\PurchaseReceiverPickProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The company's members a receipt offers to name who received the vendor's goods, with purchase.receive: whoever
 * receives goods may name a colleague without reading the members' page. The person asking comes first, never chosen
 * for them; names only, never an address.
 */
#[ApiResource(
    shortName: 'PurchaseReceiverPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/purchase-order-options/receivers',
            provider: PurchaseReceiverPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class PurchaseReceiverPickResource
{
    public const string READ = 'purchase_receiver_pick:read';

    /** The member's account, which a receipt naming them sends as `receivedBy.memberId`. */
    #[ApiProperty(identifier: false, writable: false, schema: ['type' => 'string', 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public string $memberId = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    public static function of(ReceivingMember $member): self
    {
        $resource = new self();
        $resource->memberId = $member->userId->toRfc4122();
        $resource->name = $member->name;

        return $resource;
    }
}
