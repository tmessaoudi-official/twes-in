<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Provider\QuoteDeliveryLeftProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * What of an accepted quote is still to deliver: per line, its quantity, what the company's notes that are not cancelled
 * take (a draft holds what it took), what of it the notes past their draft delivered, and what is left. Read with
 * `delivery_note.write`, as delivering it is.
 */
#[ApiResource(
    shortName: 'QuoteDeliveryLeft',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/left',
            provider: QuoteDeliveryLeftProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false],
        ),
    ],
)]
final class QuoteDeliveryLeftResource
{
    public const string READ = 'quote_delivery_left:read';

    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public string $quoteId = '';

    /**
     * @var list<array{lineId: string, quantity: string, onNotes: string, delivered: string, left: string}>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['lineId', 'quantity', 'onNotes', 'delivered', 'left'],
            'properties' => [
                'lineId' => ['type' => 'string', 'format' => 'uuid'],
                'quantity' => ['type' => 'string'],
                'onNotes' => ['type' => 'string'],
                'delivered' => ['type' => 'string'],
                'left' => ['type' => 'string'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $lines = [];

    /** @param list<array{lineId: string, quantity: string, onNotes: string, delivered: string, left: string}> $lines */
    public static function of(string $quoteId, array $lines): self
    {
        $resource = new self();
        $resource->quoteId = $quoteId;
        $resource->lines = $lines;

        return $resource;
    }
}
