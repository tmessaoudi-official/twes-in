<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * What delivering a note would do to its customer's credit limit (docs/SPEC.md § 7). Read with `delivery_note.read`:
 * it is a control for whoever delivers, who need not be allowed to read the customer's invoices. It warns; nothing
 * is refused.
 */
#[ApiResource(
    shortName: 'DeliveryNoteCredit',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/delivery-notes/{deliveryNoteId}/credit',
            provider: DeliveryNoteCreditProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
        ),
    ],
)]
final class DeliveryNoteCreditResource
{
    public const string READ = 'delivery_note_credit:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $deliveryNoteId = '';

    /** The limit that applies to the customer: theirs, else their group's, else the company's; zero is none. */
    #[Groups([self::READ])]
    public string $limit = '0';

    /** What the customer owes today across their issued invoices. */
    #[Groups([self::READ])]
    public string $owed = '0';

    #[Groups([self::READ])]
    public string $noteTotal = '0';

    /** What they would owe once this note is invoiced: `owed` plus `noteTotal`. */
    #[Groups([self::READ])]
    public string $afterDelivery = '0';

    /** The note is still to deliver, there is a limit, and `afterDelivery` passes it. */
    #[Groups([self::READ])]
    public bool $over = false;

    /** @param array{limit: string, owed: string, noteTotal: string, afterDelivery: string, over: bool} $credit */
    public static function of(string $deliveryNoteId, array $credit): self
    {
        $resource = new self();
        $resource->deliveryNoteId = $deliveryNoteId;
        $resource->limit = $credit['limit'];
        $resource->owed = $credit['owed'];
        $resource->noteTotal = $credit['noteTotal'];
        $resource->afterDelivery = $credit['afterDelivery'];
        $resource->over = $credit['over'];

        return $resource;
    }
}
