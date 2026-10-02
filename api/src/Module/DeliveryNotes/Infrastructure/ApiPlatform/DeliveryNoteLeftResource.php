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
 * What of a delivery note is still to invoice (docs/SPEC.md § 7): per line, its quantity, what the company's invoices
 * that are not cancelled already take (a draft holds what it took) and what is left. Read with `invoice.write`, as
 * invoicing a note is.
 */
#[ApiResource(
    shortName: 'DeliveryNoteLeft',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/delivery-notes/{deliveryNoteId}/left',
            provider: DeliveryNoteLeftProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
        ),
    ],
)]
final class DeliveryNoteLeftResource
{
    public const string READ = 'delivery_note_left:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $deliveryNoteId = '';

    /**
     * @var list<array{lineId: string, quantity: string, invoiced: string, left: string}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['lineId', 'quantity', 'invoiced', 'left'],
            'properties' => [
                'lineId' => ['type' => 'string', 'format' => 'uuid'],
                'quantity' => ['type' => 'string'],
                'invoiced' => ['type' => 'string'],
                'left' => ['type' => 'string'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $lines = [];

    /** @param list<array{lineId: string, quantity: string, invoiced: string, left: string}> $lines */
    public static function of(string $deliveryNoteId, array $lines): self
    {
        $resource = new self();
        $resource->deliveryNoteId = $deliveryNoteId;
        $resource->lines = $lines;

        return $resource;
    }
}
