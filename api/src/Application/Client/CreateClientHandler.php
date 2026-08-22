<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Client;

use Twes\Application\Shared\TransactionalScope;
use Twes\Domain\Client\Client;
use Twes\Domain\Client\ClientRepository;
use Twes\Domain\Client\Contact;
use Twes\Domain\Shared\IdGenerator;

/**
 * The `POST /api/clients` use case: mint an identity, assemble the aggregate, persist it, hand back what was stored.
 *
 * ## Everything happens in ONE transaction, and the read-back is inside it
 *
 * `TransactionalScope` is the only thing in this application that opens a transaction, and here it does three jobs
 * at once. It is what makes the write atomic across the parent row and its contact rows — a client briefly holding
 * no contacts, visible to a concurrent reader, would be a lie about a state it was never in. It is what BINDS THE
 * TENANT: the binding row-level security compares against is written on `beginTransaction()` and is
 * transaction-local, so outside one every statement here is refused by the policy (`CLAUDE.md` § Gotchas
 * 2026-08-07). And it is what makes the read-back below observe this transaction's own uncommitted rows.
 *
 * **THE RESPONSE IS THE CLIENT READ BACK, NOT THE AGGREGATE JUST BUILT.** The invoice write path established this
 * because `NUMERIC(21,6)` returns `2.000000` for a stored `2`, so returning the aggregate would make `POST` and a
 * later `GET` disagree byte-for-byte on one document. No column here normalises anything — every one is `text` or
 * `varchar` — so the two are expected to be identical, and that is exactly why reading back is worth its one
 * `SELECT`: it turns "the mapper's two directions agree" from an assumption into something every create exercises.
 * A mapper asymmetry that only a round trip can see is the defect this costs a query to make impossible.
 *
 * **A NULL READ-BACK IS A `\LogicException`, not a 404.** We are inside the transaction that just wrote the row, so
 * a miss means the write silently did nothing — our fault, `error.internal` per `CLAUDE.md` § "Translation keys",
 * and never something to report to a client as an absent client.
 */
final readonly class CreateClientHandler
{
    public function __construct(
        private ClientRepository $clients,
        private IdGenerator $identifiers,
        private TransactionalScope $transaction,
    ) {}

    /**
     * @throws \LogicException if the client cannot be read back inside the transaction that wrote it
     */
    public function handle(CreateClient $command): Client
    {
        return $this->transaction->transactional(function () use ($command): Client {
            // THE ID IS MINTED INSIDE THE TRANSACTION, which costs nothing and keeps one rule: nothing about this
            // client exists before the transaction that creates it. A UUIDv7 is an ordering artefact and never a
            // secret (`CLAUDE.md` § Gotchas 2026-08-05), so its only job here is to be unique and to sort.
            $client = Client::create($this->identifiers->nextIdentifier(), $command->name)
                ->withTaxIdentifier($command->taxIdentifier)
                ->withAddress($command->address);

            foreach ($command->contacts as $contact) {
                // A FRESH IDENTIFIER PER CONTACT, which is also what makes `Client`'s duplicate-contact refusal
                // unreachable from the wire: two identical contacts in one payload are two contacts, not a
                // conflict. That refusal stays because the aggregate is reachable from elsewhere — a future
                // importer, a PUT that supplies ids — and a guard removed because today's only caller cannot
                // trip it is a guard removed for the wrong reason.
                $client = $client->withContact(new Contact(
                    $this->identifiers->nextIdentifier(),
                    $contact->name,
                    $contact->email,
                    $contact->phone,
                ));
            }

            $this->clients->save($client);

            $stored = $this->clients->find($client->id());

            if (null === $stored) {
                throw new \LogicException(\sprintf(
                    'Client %s was saved and could not be read back inside the same transaction. That is not a '
                    . 'missing client — it is a write that did nothing, or a tenant binding that changed mid '
                    . 'transaction, and either is our fault rather than the caller\'s.',
                    $client->id(),
                ));
            }

            return $stored;
        });
    }
}
