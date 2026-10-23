<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Processor\DeliverQuoteProcessor;
use App\Shared\Application\ListLimits;
use App\Shared\Infrastructure\Submissions\Submissions;
use App\Shared\Infrastructure\Validation\ListLimit;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Livrer » and « Livrer une partie » on an accepted quote: a draft delivery note of its customer and establishment, each
 * line naming the quote line it delivers, at the price and with the taxes the customer accepted. With `delivery_note.write`
 * and `quote.read`; it answers 404 while the delivery notes or the quotes are off, 409 on a quote that is not accepted,
 * is on an invoice that stands, or has nothing left, and 422 on a quote with a document discount or a deposit, or on
 * quantities that name another quote's line or ask for more than is left.
 */
#[ApiResource(
    shortName: 'DeliveryNoteFromQuote',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/delivery-notes',
            defaults: [Submissions::ROUTE_DEFAULT => true],
            processor: DeliverQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            output: DeliveryNoteResource::class,
            normalizationContext: DeliveryNoteResource::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class DeliveryNoteFromQuoteResource
{
    public const string WRITE = 'delivery_note_from_quote:write';

    /**
     * How much of each line to deliver, by quote line id. With none, every line is delivered for what is left of it;
     * with some, only the lines named are, each for its quantity, which is more than nothing and no more than is left.
     *
     * @var array<string, string>|null
     */
    #[ApiProperty(schema: ['type' => ['object', 'null'], 'additionalProperties' => ['type' => 'string', 'description' => 'A decimal quantity, written with a dot.']])]
    #[Assert\Type('array', groups: [self::WRITE])]
    #[ListLimit(ListLimits::DOCUMENT_LINES, groups: [self::WRITE])]
    #[Assert\All([new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?array $quantities = null;
}
