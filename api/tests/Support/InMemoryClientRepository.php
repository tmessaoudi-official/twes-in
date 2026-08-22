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

use Twes\Domain\Client\Client;
use Twes\Domain\Client\ClientRepository;

/**
 * An in-memory {@see ClientRepository}, for testing the use-case handler without a database.
 *
 * **UNDER `tests/`, NEVER `src/`**, for the reason {@see InMemoryInvoiceRepository} gives: an in-memory store loses
 * everything on restart and would look like it works. It exists so {@see \Twes\Application\Client\CreateClientHandler}
 * can be tested for the decisions it owns — which ids, what happens in one transaction, what is handed back — rather
 * than for whether PostgreSQL persists a row, which `DoctrineClientRepositoryTest` covers against the real schema.
 *
 * **IT ENFORCES NEITHER OF THE ADAPTER'S TWO REFUSALS**, deliberately, and this list is CLOSED so that adding a
 * third to the adapter without a line here is visible:
 *
 *   1. the TENANT BOUNDARY — `save()` and `find()` refuse when no tenant is bound, because no tenant-less path may
 *      hydrate an aggregate (`CLAUDE.md` § Gotchas 2026-07-31);
 *   2. the TRANSACTION REFUSAL — both refuse outside a transaction, because the tenant binding row-level security
 *      compares against is transaction-local, so outside one a read sees nothing and a write is refused by the
 *      policy.
 *
 * A fake that reproduced them would let a handler test pass while the real adapter's version of the rule had been
 * deleted — the two-fixtures-jointly-blind failure `CLAUDE.md` § Gotchas 2026-08-07 records, where each fixture
 * supplied by hand exactly one of the two things production was missing.
 *
 * **IT ALSO DOES NOT COPY THE AGGREGATE ON THE WAY IN OR OUT**, and that is worth stating because it makes one
 * property of the real adapter invisible here: a genuine round trip goes through columns and comes back as a
 * different object, so a mapper that dropped a field would be caught. This returns the same instance, so it cannot
 * be. `Client` is `final readonly` and every mutator returns a new object, so there is no aliasing hazard in the
 * other direction — but the round trip itself is `DoctrineClientRepositoryTest`'s subject and not this class's.
 */
final class InMemoryClientRepository implements ClientRepository
{
    /** @var array<string, Client> */
    private array $clients = [];

    /** How many times `save()` was called — the only way to see a write that a later read would otherwise hide. */
    public int $saves = 0;

    /**
     * Every id handed to `find()`, in order.
     *
     * RECORDED because the handler's read-back is otherwise invisible: it returns what `save()` was given, so a
     * handler that skipped the read entirely and returned its own aggregate would produce an identical result. This
     * is what lets a test assert the read HAPPENED — the property the read-back exists for.
     *
     * @var list<string>
     */
    public array $reads = [];

    public function save(Client $client): void
    {
        ++$this->saves;
        $this->clients[$client->id()] = $client;
    }

    public function find(string $id): ?Client
    {
        $this->reads[] = $id;

        return $this->clients[$id] ?? null;
    }
}
