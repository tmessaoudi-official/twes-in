<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Support;

use Twes\Domain\Product\Product;
use Twes\Domain\Product\ProductRepository;

/**
 * An in-memory {@see ProductRepository}, for testing the use-case handler without a database.
 *
 * **UNDER `tests/`, NEVER `src/`**, for the reason {@see InMemoryInvoiceRepository} gives: an in-memory store
 * loses everything on restart and would look like it works.
 *
 * **IT DOES NOT SIMULATE NUMERIC RE-SCALING, and for a PRODUCT that omission is bigger than it was for a
 * client.** `cost_amount` is `NUMERIC(19,4)` against a TND scale of three, and `profit_rate` is
 * `NUMERIC(27,12)`, so what PostgreSQL returns is not character-identical to what was written. A fake that
 * reproduced that would be re-implementing a database. The consequence is stated so it is not mistaken for
 * coverage: the handler's read-back is *structurally* visible here (the saved instance comes back) and its
 * POINT — that `POST` and a later `GET` agree, and that a typed millime survives — can only be proven against
 * real columns, which is what `DoctrineProductRepositoryTest` is for.
 *
 * **IT ENFORCES NEITHER OF THE ADAPTER'S TWO REFUSALS**, deliberately, and this list is CLOSED so that adding a
 * third to the adapter without a line here is visible: the TENANT BOUNDARY and the TRANSACTION REFUSAL. A fake
 * that reproduced them would let a handler test pass while the real adapter's version of the rule had been
 * deleted.
 */
final class InMemoryProductRepository implements ProductRepository
{
    /** @var array<string, Product> */
    private array $products = [];

    /** How many times `save()` was called. */
    public int $saves = 0;

    /**
     * Every id handed to `find()`, in order.
     *
     * RECORDED because the handler's read-back is otherwise invisible: it returns what `save()` was given, so a
     * handler that skipped the read and returned its own aggregate would produce an identical result.
     *
     * @var list<string>
     */
    public array $reads = [];

    public function save(Product $product): void
    {
        ++$this->saves;
        $this->products[$product->id()] = $product;
    }

    public function find(string $id): ?Product
    {
        $this->reads[] = $id;

        return $this->products[$id] ?? null;
    }
}
