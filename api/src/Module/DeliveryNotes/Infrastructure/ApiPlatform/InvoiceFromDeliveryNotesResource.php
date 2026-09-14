<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoiceResource;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An invoice drafted from delivery notes (docs/SPEC.md § 7, 2026-09-14), with invoice.write: the answer is the draft
 * invoice. It belongs to the delivery notes module, so it answers 404 while that module is off, and while invoices are.
 */
#[ApiResource(
    shortName: 'InvoiceFromDeliveryNotes',
    operations: [
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/from-delivery-notes',
            processor: DraftInvoiceFromDeliveryNotesProcessor::class,
            security: 'is_granted("ROLE_USER")',
            output: InvoiceResource::class,
            normalizationContext: InvoiceResource::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class InvoiceFromDeliveryNotesResource
{
    public const string WRITE = 'invoice_from_delivery_notes:write';

    /**
     * Validated or delivered notes of one customer and one establishment, none on an invoice that is not cancelled.
     *
     * @var list<string>
     */
    #[ApiProperty(schema: ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'format' => 'uuid']])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::WRITE])]
    public array $deliveryNoteIds = [];
}
