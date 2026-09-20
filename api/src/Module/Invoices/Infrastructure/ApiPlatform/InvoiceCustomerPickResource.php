<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The customers the invoice form offers while a person types (docs/SPEC.md § 7, 2026-09-17, ruling 3: a 300 ms pause,
 * 20 results). The same shape `InvoiceOptionsResource::$customers` carried, asked for a few at a time instead of
 * whole, because a company's whole book of customers is not a list anybody scrolls.
 *
 * It lives in this module, under the invoice's own permission, so the rule the options endpoint states still holds:
 * someone who writes invoices need not also read customers to fill one in. The search belongs to the Customers
 * module (`PickCustomers`), which keeps this a thin adapter and the dependency pointing the one way it should.
 *
 * There is no total and no page: a picker asks again on every keystroke, and counting a large book each time buys
 * nothing a person would read. So this list is capped, never paged, and carries no Hydra.
 */
#[ApiResource(
    shortName: 'InvoiceCustomerPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/invoice-options/customers',
            name: self::PICK,
            provider: InvoiceCustomerPickProvider::class,
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
final class InvoiceCustomerPickResource
{
    public const string READ = 'invoice_customer_pick:read';
    public const string PICK = 'invoice_customer_pick';

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

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public ?string $defaultDiscountRate = null;

    /** @var list<string> */
    #[ApiProperty(required: true, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public array $defaultTaxComponentIds = [];

    /** @param array{id: string, number: string, name: string, excludedFamilies: list<string>, defaultDiscountRate: string|null, defaultTaxComponentIds: list<string>} $pick */
    public static function of(array $pick): self
    {
        $resource = new self();
        $resource->id = $pick['id'];
        $resource->number = $pick['number'];
        $resource->name = $pick['name'];
        $resource->excludedFamilies = $pick['excludedFamilies'];
        $resource->defaultDiscountRate = $pick['defaultDiscountRate'];
        $resource->defaultTaxComponentIds = $pick['defaultTaxComponentIds'];

        return $resource;
    }
}
