<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Product;

use Twes\Application\Shared\TransactionalScope;
use Twes\Domain\Product\Product;
use Twes\Domain\Product\ProductRepository;
use Twes\Domain\Shared\IdGenerator;

/**
 * The `POST /api/products` use case: mint an identity, assemble the aggregate, persist it, hand back what was
 * stored.
 *
 * **THE RESPONSE IS THE PRODUCT READ BACK, and here that is worth more than it was for a client.** The client
 * write path reads back on principle, since no column normalises anything. This one has real normalisation to
 * catch: `cost_amount` is `NUMERIC(19,4)` while TND has THREE decimals, and `profit_rate` is `NUMERIC(27,12)`,
 * so what PostgreSQL returns is not character-identical to what was sent. Reading back is what makes `POST` and
 * a later `GET` agree — the rule the invoice write path established when `NUMERIC(21,6)` returned `2.000000`
 * for a stored `2`.
 *
 * **A NULL READ-BACK IS A `\LogicException`, not a 404.** We are inside the transaction that just wrote the row,
 * so a miss means the write silently did nothing — our fault, `error.internal`, and never something to report to
 * a client as an absent product.
 */
final readonly class CreateProductHandler
{
    public function __construct(
        private ProductRepository $products,
        private IdGenerator $identifiers,
        private TransactionalScope $transaction,
    ) {}

    /**
     * @throws \LogicException if the product cannot be read back inside the transaction that wrote it
     */
    public function handle(CreateProduct $command): Product
    {
        return $this->transaction->transactional(function () use ($command): Product {
            $product = Product::create(
                $this->identifiers->nextIdentifier(),
                $command->name,
                $command->pricing,
                $command->vatRate,
            )->withSku($command->sku);

            $this->products->save($product);

            $stored = $this->products->find($product->id());

            if (null === $stored) {
                throw new \LogicException(\sprintf(
                    'Product %s was saved and could not be read back inside the same transaction. That is not a '
                    . 'missing product — it is a write that did nothing, or a tenant binding that changed mid '
                    . 'transaction, and either is our fault rather than the caller\'s.',
                    $product->id(),
                ));
            }

            return $stored;
        });
    }
}
