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
use App\Module\DeliveryNotes\Application\InvoiceDeliveryNotes;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Processor\InvoicingCheckProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What invoicing the delivery notes a person selected would do, before it runs,
 * with invoice.write: each id once, in the order sent, `included` or `skipped` with a `reason` the screen translates,
 * and the `customerId` and `establishmentId` of the invoice the included ones make.
 * The invoice is the draft `invoiceId` names, or else a new one for the first note that can go on it. Nothing is
 * changed. More than a list page of ids, none, or one that is not an identifier, 422; an invoice that is not the
 * company's, 404; one that is no draft any more, 409. Like drafting, it answers 404 while either module is off.
 */
#[ApiResource(
    shortName: 'InvoicingCheck',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/from-delivery-notes/check',
            processor: InvoicingCheckProcessor::class,
            security: 'is_granted("ROLE_USER")',
            status: 200,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class InvoicingCheckResource
{
    public const string READ = 'invoicing_check:read';
    public const string WRITE = 'invoicing_check:write';

    private const array ROWS = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'number', 'customerName', 'outcome', 'reason'],
            'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'number' => ['type' => ['string', 'null']],
                'customerName' => ['type' => ['string', 'null']],
                'outcome' => ['type' => 'string', 'enum' => ['included', 'skipped']],
                'reason' => ['type' => ['string', 'null'], 'enum' => ['not_found', 'draft', 'cancelled', 'invoiced', 'nothing_left', 'other_customer', 'other_establishment', null]],
            ],
        ],
    ];

    /** @var list<string> the selected notes' ids, a list page at most */
    #[ApiProperty(schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'minItems' => 1, 'maxItems' => InvoiceDeliveryNotes::MOST])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(min: 1, max: InvoiceDeliveryNotes::MOST, groups: [self::WRITE])]
    #[Assert\All([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public array $deliveryNoteIds = [];

    /** The draft the notes would be added to; none for a new invoice. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public ?string $invoiceId = null;

    /** @var list<array{id: string, number: ?string, customerName: ?string, outcome: string, reason: ?string}> */
    #[ApiProperty(writable: false, schema: self::ROWS)]
    #[Groups([self::READ])]
    public array $rows = [];

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $included = 0;

    /** The customer of the invoice the notes that go in make, to find its drafts; null when none goes in. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public ?string $customerId = null;

    /** Its establishment, whose series numbers it; null when none goes in. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public ?string $establishmentId = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $skipped = 0;
}
