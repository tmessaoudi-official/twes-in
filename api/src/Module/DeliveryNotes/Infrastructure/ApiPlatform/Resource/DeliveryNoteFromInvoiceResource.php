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
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Processor\DeliverInvoiceProcessor;
use App\Shared\Application\ListLimits;
use App\Shared\Infrastructure\Submissions\Submissions;
use App\Shared\Infrastructure\Validation\ListLimit;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * « Bon de livraison » and « Livrer une partie » on an issued invoice: a draft delivery note of its customer and
 * establishment, each line naming the invoice line whose goods it hands over, at the price and with the taxes billed. The
 * invoice took the goods out of stock and billed them: the note moves no stock and is never invoiced. With
 * `delivery_note.write` and `invoice.read`; it answers 404 while the delivery notes or the invoices are off, 409 on a
 * draft, a credit note or a deposit invoice, or an invoice with nothing left, and 422 on quantities that name a line of
 * no goods or ask for more than its credit notes and other notes leave. `…/invoices/{id}/delivery-notes` adds notes to a
 * draft invoice, hence this address.
 */
#[ApiResource(
    shortName: 'DeliveryNoteFromInvoice',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/deliveries',
            defaults: [Submissions::ROUTE_DEFAULT => true],
            processor: DeliverInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            output: DeliveryNoteResource::class,
            normalizationContext: DeliveryNoteResource::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class DeliveryNoteFromInvoiceResource
{
    public const string WRITE = 'delivery_note_from_invoice:write';

    /**
     * How much of each line to deliver, by invoice line id. With none, every line of goods is delivered for what is left of it;
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
