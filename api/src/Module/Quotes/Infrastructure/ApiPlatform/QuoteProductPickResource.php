<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * The products the quote form offers while a person types a line, the invoice picker's shape, asked for a few at a
 * time: a catalogue of twenty thousand is a payload nobody waits for and a dropdown nobody scrolls.
 *
 * Same placement rule as the customer picker beside it: the quote's own permission here, the search in the Products
 * module (`PickProducts`), no total and no page. Quoting does not need products: with them switched off it answers
 * none, and every line is written by hand.
 */
#[ApiResource(
    shortName: 'QuoteProductPick',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/quote-options/products',
            name: self::PICK,
            provider: QuoteProductPickProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ], 'skip_null_values' => false],
            parameters: [
                'q' => new QueryParameter(
                    schema: ['type' => 'string', 'maxLength' => 100],
                    description: 'Words found in the reference or name, or one of its codes spelled whole (which is then offered first), whatever their case and accents; under three characters, the exact reference only. Left out, the first few by reference.',
                ),
                'ids' => new QueryParameter(
                    schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'maxItems' => 20],
                    description: 'Resolves records a document already names, rather than searching: what a form needs to show what is on it. Unlike a search, this answers a record that has since been deactivated, because a document written last year still names it. Given, `q` is ignored.',
                ),
            ],
        ),
    ],
)]
final class QuoteProductPickResource
{
    public const string READ = 'quote_product_pick:read';
    public const string PICK = 'quote_product_pick';

    // No identifier: reached only through the uriTemplate above.
    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $reference = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $name = '';

    /** The unit the product is sold in, which a line's quantity is counted in. */
    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $unitId = '';

    #[ApiProperty(required: true)]
    #[Groups([self::READ])]
    public string $unitPriceNet = '';

    /** @var list<string> */
    #[ApiProperty(required: true, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public array $defaultTaxComponentIds = [];

    /** How its stock is told apart: none, lot or serial; a line of a tracked product may name the one handed over. */
    #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => ['none', 'lot', 'serial']])]
    #[Groups([self::READ])]
    public string $tracking = 'none';

    /** @param array{id: string, reference: string, name: string, unitId: string, unitPriceNet: string, defaultTaxComponentIds: list<string>, tracking: string} $pick */
    public static function of(array $pick): self
    {
        $resource = new self();
        $resource->id = $pick['id'];
        $resource->reference = $pick['reference'];
        $resource->name = $pick['name'];
        $resource->unitId = $pick['unitId'];
        $resource->unitPriceNet = $pick['unitPriceNet'];
        $resource->defaultTaxComponentIds = $pick['defaultTaxComponentIds'];
        $resource->tracking = $pick['tracking'];

        return $resource;
    }
}
