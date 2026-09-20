<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The customers the delivery note form offers while a person types (docs/SPEC.md § 7, 2026-09-17, ruling 3). The same
 * shape `DeliveryNoteOptionsResource::$customers` carried, asked for a few at a time instead of whole.
 *
 * It is NARROWER than the invoice picker's row beside it, and deliberately so: a delivery note charges nothing, so it
 * has no use for a customer's default discount or its default document taxes, and a picker answers what the form it
 * serves actually needs. That is the reason there is one picker per consumer rather than one shared "customers"
 * endpoint: the shape and the permission both belong to the form asking.
 */
#[ApiResource(
    shortName: 'DeliveryNoteCustomerPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/delivery-note-options/customers',
            name: self::PICK,
            provider: DeliveryNoteCustomerPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 100],
                    description: 'Words found in the number, name, email, billing address or registration numbers, whatever their case and accents; under three characters, the exact number only. Left out, the first few by number.',
                ),
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 20],
                    description: 'Resolves records a document already names, rather than searching: what a form needs to show what is on it. Unlike a search, this answers a record that has since been deactivated, because a document written last year still names it. Given, `q` is ignored.',
                ),
            ],
        ),
    ],
)]
final class DeliveryNoteCustomerPickResource
{
    public const string READ = 'delivery_note_customer_pick:read';
    public const string PICK = 'delivery_note_customer_pick';

    // No identifier: reached only through the uriTemplate above.
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $number = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    /**
     * The tax families this customer's regime leaves out, so a line never offers a tax the customer cannot carry.
     *
     * @var list<string>
     */
    #[ApiProperty(required: true, schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['vat', 'levy', 'stamp', 'withholding']]])]
    #[Groups([self::READ])]
    public array $excludedFamilies = [];

    /** @param array{id: string, number: string, name: string, excludedFamilies: list<string>, defaultDiscountRate: string|null, defaultTaxComponentIds: list<string>} $pick */
    public static function of(array $pick): self
    {
        $resource = new self();
        $resource->id = $pick['id'];
        $resource->number = $pick['number'];
        $resource->name = $pick['name'];
        $resource->excludedFamilies = $pick['excludedFamilies'];

        return $resource;
    }
}
