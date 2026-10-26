<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Module\Invoices\Infrastructure\ApiPlatform\State\Provider\InvoiceReceiverPickProvider;
use App\Shared\Application\Receivers\ReceiverContact;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The customer's contacts an invoice offers to name who received the goods it hands over, under the invoice's own
 * permission: a person who writes invoices may name a contact without reading the customer's file. Names only, never a
 * contact's phone or address.
 */
#[ApiResource(
    shortName: 'InvoiceReceiverPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/invoice-options/customers/{customerId}/receivers',
            provider: InvoiceReceiverPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class InvoiceReceiverPickResource
{
    public const string READ = 'invoice_receiver_pick:read';

    /** The contact's id, which an invoice naming it sends as `receivedBy.contactId`. */
    #[ApiProperty(identifier: false, writable: false, schema: ['type' => 'string', 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public string $contactId = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    public static function of(ReceiverContact $contact): self
    {
        $resource = new self();
        $resource->contactId = $contact->id->toRfc4122();
        $resource->name = $contact->name;

        return $resource;
    }
}
