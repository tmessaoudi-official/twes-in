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
use App\Module\DeliveryNotes\Application\Invoicing\InvoiceDeliveryNotes;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Processor\DraftInvoicesFromDeliveryNotesProcessor;
use App\Shared\Infrastructure\Submissions\Submissions;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The draft invoices made from delivery notes in bulk, with invoice.write: one for each customer and establishment the
 * notes name where the company invoices each (delivery_note.mixed_customers_invoicing), else one, notes of two customers
 * or establishments refused as POST .../invoices/from-delivery-notes refuses them. Every line of every note is invoiced
 * for what is left of it, all in one transaction. Like drafting one invoice, it answers 404 while either module is off.
 */
#[ApiResource(
    shortName: 'InvoicesFromDeliveryNotes',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/from-delivery-notes/each',
            defaults: [Submissions::ROUTE_DEFAULT => true],
            processor: DraftInvoicesFromDeliveryNotesProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class InvoicesFromDeliveryNotesResource
{
    public const string READ = 'invoices_from_delivery_notes:read';
    public const string WRITE = 'invoices_from_delivery_notes:write';

    /** @var list<string> validated or delivered notes, none on an invoice that is not cancelled, a list page at most */
    #[ApiProperty(schema: ['type' => 'array', 'minItems' => 1, 'maxItems' => InvoiceDeliveryNotes::MOST, 'items' => ['type' => 'string', 'format' => 'uuid']])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(max: InvoiceDeliveryNotes::MOST, groups: [self::WRITE], payload: ['reason' => 'selection_count', 'params' => ['max' => InvoiceDeliveryNotes::MOST]])]
    #[Assert\All([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public array $deliveryNoteIds = [];

    /** @var list<string> the drafts made, in the order of each one's first note */
    #[ApiProperty(writable: false, schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']])]
    #[Groups([self::READ])]
    public array $invoiceIds = [];
}
