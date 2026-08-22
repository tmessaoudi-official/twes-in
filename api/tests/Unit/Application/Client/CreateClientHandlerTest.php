<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Application\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Application\Client\CreateClient;
use Twes\Application\Client\CreateClientHandler;
use Twes\Application\Client\NewContact;
use Twes\Domain\Client\Client;
use Twes\Domain\Client\PostalAddress;
use Twes\Tests\Support\FixedIdGenerator;
use Twes\Tests\Support\InMemoryClientRepository;
use Twes\Tests\Support\RecordingTransactionalScope;

/**
 * The create-client use case, tested for the decisions it OWNS.
 *
 * Not for whether PostgreSQL stores a row — `DoctrineClientRepositoryTest` does that against the real schema, and
 * duplicating it here with a fake would prove only that the fake works. What is here is what the handler decides:
 * that identity comes from the port and never from the caller, that a contact gets its own identifier, that the
 * whole thing is ONE unit of work, and that the response is what was read back rather than what was built.
 */
#[CoversClass(CreateClientHandler::class)]
final class CreateClientHandlerTest extends TestCase
{
    private InMemoryClientRepository $clients;
    private FixedIdGenerator $identifiers;
    private RecordingTransactionalScope $transaction;
    private CreateClientHandler $handler;

    protected function setUp(): void
    {
        $this->clients = new InMemoryClientRepository();
        $this->identifiers = new FixedIdGenerator();
        $this->transaction = new RecordingTransactionalScope();
        $this->handler = new CreateClientHandler($this->clients, $this->identifiers, $this->transaction);
    }

    public function testTheClientIdComesFromThePortAndNeverFromTheCaller(): void
    {
        $client = $this->handler->handle(self::command('Acme'));

        self::assertSame(
            $this->identifiers->handedOut[0],
            $client->id(),
            'the id must be the one the generator issued — a caller cannot choose a primary key',
        );
    }

    /**
     * **EVERY CONTACT GETS ITS OWN IDENTIFIER, and the count is what makes that checkable.**
     *
     * A handler that minted one id and reused it for both contacts would be refused by `Client`'s own duplicate
     * guard — so this case would fail loudly either way. What it pins beyond that is the ORDER: three identifiers
     * for a client with two contacts, the client's first.
     */
    public function testEachContactIsGivenItsOwnIdentifier(): void
    {
        $client = $this->handler->handle(new CreateClient('Acme', null, null, [
            new NewContact('Amine', null, null),
            new NewContact('Yasmine', null, null),
        ]));

        self::assertCount(3, $this->identifiers->handedOut, 'one id for the client, one per contact');
        self::assertSame($this->identifiers->handedOut[0], $client->id());
        self::assertSame($this->identifiers->handedOut[1], $client->contacts()[0]->id);
        self::assertSame($this->identifiers->handedOut[2], $client->contacts()[1]->id);
    }

    /**
     * **THE ORDER SENT IS THE ORDER STORED**, which `NewClientInput` states as a contract commitment.
     *
     * Asserted on the NAMES rather than the ids, because the ids are assigned in iteration order and would agree
     * with themselves however the list were shuffled — a check deriving its expectation from the thing it is
     * checking, which `CLAUDE.md` § Gotchas 2026-07-31 records as a P0 shape.
     */
    public function testTheContactOrderIsTheOrderTheCallerSent(): void
    {
        $client = $this->handler->handle(new CreateClient('Acme', null, null, [
            new NewContact('Zoubeir', null, null),
            new NewContact('Amine', null, null),
        ]));

        self::assertSame(
            ['Zoubeir', 'Amine'],
            array_map(static fn($contact): string => $contact->name, $client->contacts()),
            'nothing may sort this list — the order is what the caller arranged',
        );
    }

    /**
     * ONE unit of work, entered once and never nested.
     *
     * The parent row and its contact rows are written by one `save()`, and a partial commit would leave a client
     * visible with contacts it never had. `$maxDepth` is what distinguishes "entered once" from "entered twice":
     * DBAL turns a nested `beginTransaction()` into a SAVEPOINT, so a handler that opened a second scope would look
     * transactional while its second half could roll back alone.
     */
    public function testTheWholeCreationIsOneTransaction(): void
    {
        $this->handler->handle(self::command('Acme'));

        self::assertSame(1, $this->transaction->entered, 'exactly one scope, opened by the handler');
        self::assertSame(1, $this->transaction->maxDepth, 'and never nested inside another');
        self::assertSame(1, $this->clients->saves);
    }

    /**
     * **THE RESPONSE IS READ BACK, and the recorded read is the only way to see it.**
     *
     * The fake returns the instance it was given, so a handler that skipped the read and returned its own aggregate
     * would produce an identical `Client` — the two are indistinguishable by value. What is distinguishable is
     * whether the repository was ASKED, which is why `InMemoryClientRepository::$reads` exists. Against real
     * columns the read-back means something stronger, and `DoctrineClientRepositoryTest` is where that lives.
     */
    public function testTheStoredClientIsReadBackRatherThanReturnedFromMemory(): void
    {
        $client = $this->handler->handle(self::command('Acme'));

        self::assertSame(
            [$client->id()],
            $this->clients->reads,
            'the handler must read the client back inside its own transaction',
        );
    }

    /** The optional fields survive intact, including an address that is present as a whole. */
    public function testTheOptionalFieldsReachTheAggregate(): void
    {
        $address = new PostalAddress('12 Rue de la Paix', 'Bâtiment C', '75002', 'Paris', 'FR');

        $client = $this->handler->handle(new CreateClient('Acme', 'TN1234567X', $address, []));

        self::assertSame('TN1234567X', $client->taxIdentifier());
        self::assertEquals($address, $client->address());
    }

    /** A client with nothing but a name — the ordinary case on the day somebody types one in. */
    public function testAClientMayHaveNothingButAName(): void
    {
        $client = $this->handler->handle(self::command('Acme'));

        self::assertSame('Acme', $client->name());
        self::assertNull($client->taxIdentifier());
        self::assertNull($client->address());
        self::assertSame([], $client->contacts());
    }

    /**
     * A read-back that finds nothing is OUR fault and must not become a 404.
     *
     * We are inside the transaction that just wrote the row, so a miss means the write silently did nothing. A
     * `\LogicException` is `error.internal` per `CLAUDE.md` § "Translation keys"; anything the transport could
     * mistake for "no such client" would report a broken write as an absent client, which is the corrupt-row-404
     * defect § Gotchas records on the invoice read path.
     */
    public function testAClientThatCannotBeReadBackIsOurFaultRatherThanAMissingClient(): void
    {
        $handler = new CreateClientHandler(new NeverStoringClientRepository(), $this->identifiers, $this->transaction);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/could not be read back/');

        $handler->handle(self::command('Acme'));
    }

    private static function command(string $name): CreateClient
    {
        return new CreateClient($name, null, null, []);
    }
}

/**
 * A repository whose `save()` silently does nothing — the shape a broken adapter would have.
 *
 * Its own class rather than a configurable flag on {@see InMemoryClientRepository}, because a flag that makes a
 * fake lie is a flag somebody eventually leaves set.
 */
final class NeverStoringClientRepository implements \Twes\Domain\Client\ClientRepository
{
    public function save(Client $client): void {}

    public function find(string $id): ?Client
    {
        return null;
    }
}
