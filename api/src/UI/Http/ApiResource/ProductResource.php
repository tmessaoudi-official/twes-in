<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use Twes\UI\Http\State\CreateProductProcessor;
use Twes\UI\Http\State\ProductProvider;

/**
 * One of the calling company's catalogue products, as the API returns it.
 *
 * ## The two operations, and the ones deliberately absent
 *
 * `POST /api/products` creates one; `GET /api/products/{id}` fetches one. **No collection `GET`, no `PUT`, no
 * `DELETE`**, each for a reason rather than as an unfinished surface — the same three arguments
 * {@see ClientResource} records, plus one specific to a catalogue:
 *
 * - **No collection `GET` yet**: a list needs pagination decided once, and `CLAUDE.md` § "The API contract is
 *   ours to design" rejects upstream's `per_page=999999` outright. A catalogue is also the first resource that
 *   will want SEARCH (by name, by SKU), and bolting a filter onto an unpaginated list is how that gets decided
 *   by accident.
 * - **No `PUT` yet, and here the missing decision is a pricing one rather than a shape one.** Editing a product
 *   means answering what an edit does to AUTHORSHIP: F4 rules that changing the cost preserves the RATE and
 *   moves the price, that typing a price transfers authorship to the price, and that a zero old cost has no rate
 *   to preserve. `ProductPricing` implements all three; what does not exist is the contract for expressing
 *   "which field did the user just edit" over HTTP. Inventing that as a side effect of adding an edit endpoint
 *   would decide a tax-adjacent rule by accident.
 * - **No `DELETE`**: what happens to an issued invoice whose line came from this product is Wave 2's question.
 *   The F4 snapshot rule means the ANSWER is probably "nothing" — a line carries its `Money` and `Rate` by value
 *   and holds no product id — but "probably" is not a contract.
 *
 * **THE TENANT IS NOT IN THE PATH**, for the reason `ClientResource` gives: tenancy is ambient context, and a
 * tenant id in the URL would be a second, forgeable answer to a question the request already answers.
 */
#[ApiResource(
    shortName: 'Product',
    operations: [
        new Get(
            uriTemplate: '/products/{id}',
            provider: ProductProvider::class,
        ),
        new Post(
            uriTemplate: '/products',
            input: NewProductInput::class,
            // WITHOUT THIS a type mismatch anywhere in the body -- a JSON number where a decimal string is
            // declared -- produces an opaque `400 {"detail":"The input data is misformatted."}` naming no field.
            // On this resource that matters more than anywhere else: every monetary and rate field is a string
            // precisely BECAUSE a JSON number would silently lose precision, so the error that says which field
            // was sent as a number is the one teaching a client implementer the rule.
            denormalizationContext: ['collect_denormalization_errors' => true],
            processor: CreateProductProcessor::class,
        ),
    ],
)]
final readonly class ProductResource
{
    public function __construct(
        /** Server-generated. Stable for the life of the product. */
        public string $id,
        /** What appears on the invoice line a client reads. Never empty. */
        public string $name,
        /** The stock-keeping unit, or absent. Deliberately NOT unique — see the migration. */
        public ?string $sku,
        /** Cost, authorship, and both price figures — see {@see ProductPricingResource}. */
        public ProductPricingResource $pricing,
        /**
         * The VAT rate to place on a line created from this product, as a PERCENTAGE — `19`, not `0.19`.
         *
         * On the ITEM rather than in company settings, because a foodstuff and a service are taxed differently
         * inside one company. Ten decimal places, canonical rather than formatted, exactly like the profit rate.
         */
        public string $vatRate,
    ) {}
}
