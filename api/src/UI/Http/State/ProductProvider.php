<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twes\Application\Shared\TransactionalScope;
use Twes\Domain\Product\Product;
use Twes\Domain\Product\ProductRepository;
use Twes\Domain\Shared\Identifier;
use Twes\UI\Http\ApiResource\ProductResource;

/**
 * `GET /api/products/{id}` — fetch one catalogue product belonging to the current tenant.
 *
 * The TRANSLATION lives in {@see ProductRepresentation}, shared with the write path so a create response and a
 * later fetch cannot describe the same product differently. What is left here is this operation's own job:
 * turning a route variable into a lookup, and turning every way that lookup can fail into the same answer.
 *
 * @implements ProviderInterface<ProductResource>
 */
final readonly class ProductProvider implements ProviderInterface
{
    public function __construct(
        private ProductRepository $products,
        private TransactionalScope $transaction,
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @throws NotFoundHttpException if the id is not a string, is not a canonical UUID, or names no product this
     *                               tenant can see
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        $id = $uriVariables['id'] ?? null;

        // A NON-STRING ID IS A 404, NOT A 500. API Platform hands over whatever matched the route, and an
        // ill-formed id is a caller error rather than a server one.
        if (!\is_string($id)) {
            throw new NotFoundHttpException('A product id must be a string.');
        }

        // CHECKED BEFORE THE LOOKUP, NOT BY CATCHING THE REPOSITORY'S REFUSAL. `CLAUDE.md` § Gotchas records
        // `InvoiceProvider` wrapping its whole lookup in `catch (\InvalidArgumentException)` to translate this
        // refusal — and that catch was wide enough to swallow a HYDRATION failure, so a product whose stored
        // pricing no longer parses would answer `404 No such product.` while the row demonstrably existed.
        //
        // That risk is REAL here rather than theoretical: `pricingFrom()` raises `\LogicException` for a row
        // whose discriminator disagrees with its columns, and `Money::of()` raises `\InvalidArgumentException`
        // for a stored amount that no longer fits its currency's scale. With the check up front there is no
        // catch, so both propagate as a 500 — `error.internal`, which is what our own fault should answer.
        //
        // 404 RATHER THAN 400 for a malformed id: distinguishing "malformed" from "absent" tells a prober its
        // guess had the right SHAPE. Both answers are "no such product".
        if (!Identifier::isWellFormed($id)) {
            throw new NotFoundHttpException('No such product.');
        }

        // THE READ RUNS IN A TRANSACTION, and without one it returns nothing. The tenant binding row-level
        // security compares against is written on `beginTransaction()` and is transaction-local, so a query
        // issued outside one is issued UNBOUND and the row is invisible: a tenant asking for its own product
        // would get a 404. `ProductRepository::find()` refuses outside a transaction rather than answering
        // wrongly, so this wrapper is what keeps that refusal from ever firing.
        $product = $this->transaction->transactional(fn(): ?Product => $this->products->find($id));

        if (null === $product) {
            // NULL COVERS BOTH "does not exist" AND "belongs to another tenant", indistinguishably. An error
            // naming the product would confirm its existence — and a catalogue is a company's margin structure,
            // so its contents are exactly what a competitor would want to enumerate.
            throw new NotFoundHttpException('No such product.');
        }

        return ProductRepresentation::of($product);
    }
}
