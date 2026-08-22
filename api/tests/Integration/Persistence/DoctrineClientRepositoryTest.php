<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Client\Client;
use Twes\Domain\Client\Contact;
use Twes\Domain\Client\PostalAddress;
use Twes\Infrastructure\Persistence\Doctrine\DoctrineClientRepository;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Tests\Integration\Tenancy\MigratedProbeDatabase;

/**
 * The client repository against a REAL migrated schema, which is the only place several of these can be proven.
 *
 * Against a real schema rather than a fake, because what is being tested is precisely what an in-memory double
 * cannot have: the `client_address_is_whole` CHECK, the composite foreign key's cascade, the
 * `ORDER BY position` a plan is otherwise free to ignore, and the two refusals that exist because row-level
 * security is transaction-local.
 */
#[CoversClass(DoctrineClientRepository::class)]
final class DoctrineClientRepositoryTest extends TestCase
{
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_client_repository_probe';
    private const TENANT_A = '0199a5b2-0000-7000-8000-0000000002aa';
    private const TENANT_B = '0199a5b2-0000-7000-8000-0000000002bb';
    private const CLIENT = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    private const CONTACT_A = '0199a5b2-0000-7000-8000-00000000c001';
    private const CONTACT_B = '0199a5b2-0000-7000-8000-00000000c002';

    private static ?Connection $connection = null;

    public static function setUpBeforeClass(): void
    {
        self::createMigratedProbeDatabase(self::DATABASE);
    }

    public static function tearDownAfterClass(): void
    {
        self::$connection = null;
        self::dropProbeDatabase(self::DATABASE);
    }

    /**
     * EVERY CASE OWNS ITS OWN CLIENT ROW, cleaned before it runs.
     *
     * `CLAUDE.md` § Gotchas 2026-08-07 records two pre-existing order-dependent cases in the invoice suite that
     * shared one document id with no `setUp()`, so one wrote a draft over a row an earlier case had issued. The
     * cheapest way not to repeat that is to start from a known-empty table.
     */
    protected function setUp(): void
    {
        self::connection()->executeStatement('DELETE FROM client_contact');
        self::connection()->executeStatement('DELETE FROM client');
    }

    /** The whole aggregate, with everything populated, survives a real round trip. */
    public function testAFullClientSurvivesARoundTripThroughRealColumns(): void
    {
        $client = Client::create(self::CLIENT, 'Société Générale de Test')
            ->withTaxIdentifier('TN1234567X')
            ->withAddress(new PostalAddress('12 Rue de la Paix', 'Bâtiment C', '75002', 'Paris', 'FR'))
            ->withContact(new Contact(self::CONTACT_A, 'Amine', 'amine@example.test', '+216 71 000 000'))
            ->withContact(new Contact(self::CONTACT_B, 'Yasmine', null, null));

        $repository = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $repository->save($client));

        $restored = self::inTransaction(static fn(): ?Client => $repository->find(self::CLIENT));

        self::assertNotNull($restored, 'the client must be readable back');
        self::assertSame(self::CLIENT, $restored->id());
        self::assertSame('Société Générale de Test', $restored->name());
        self::assertSame('TN1234567X', $restored->taxIdentifier());

        $address = $restored->address();
        self::assertNotNull($address);
        self::assertSame('12 Rue de la Paix', $address->line1);
        self::assertSame('Bâtiment C', $address->line2);
        self::assertSame('75002', $address->postcode);
        self::assertSame('Paris', $address->city);
        self::assertSame('FR', $address->countryCode);

        self::assertCount(2, $restored->contacts());
        self::assertSame('amine@example.test', $restored->contacts()[0]->email);
        self::assertNull($restored->contacts()[1]->email, 'an absent e-mail comes back absent, not empty');
    }

    /** A client with nothing but a name and an id — the ordinary state on the day somebody types one in. */
    public function testAMinimalClientSurvivesARoundTrip(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $repository->save(Client::create(self::CLIENT, 'Acme')));

        $restored = self::inTransaction(static fn(): ?Client => $repository->find(self::CLIENT));

        self::assertNotNull($restored);
        self::assertNull($restored->taxIdentifier());
        self::assertNull($restored->address(), 'no address must come back as no address, not a blank one');
        self::assertSame([], $restored->contacts());
    }

    /**
     * **CONTACT ORDER SURVIVES, AND THE `ORDER BY position` IS WHAT MAKES THAT TRUE.**
     *
     * **THE PLANNER HINTS ARE THE WHOLE TEST, and it took two wrong fixtures and an `EXPLAIN` to find that
     * out.** The first version simply saved two contacts and read them back; deleting `ORDER BY position` from
     * the repository left it GREEN. The second tried to disturb the physical order — first with an `UPDATE`
     * (which was a HOT update and did not move the row at all), then with a delete-and-reinsert (which did) —
     * and the read STILL came back in position order.
     *
     * `EXPLAIN` gave the reason, and it is not about the heap: the planner satisfies
     * `WHERE company_id = ? AND client_id = ?` with an **index scan on
     * `client_contact_position_unique_per_client`**, whose third column IS `position` — so that access path
     * returns rows in position order *by construction*, whatever order they were written in. The clause was
     * unobservable because the cheapest plan happened to provide the same guarantee.
     *
     * That does NOT make it an equivalent mutant, and this is the distinction § Gotchas 2026-07-29 records two
     * refuted "equivalent mutant" claims about. The planner is free to choose a sequential scan — a bigger
     * table, different statistics, a dropped index — and then row order is arbitrary. So the `ORDER BY` is
     * load-bearing in general and merely *masked* here, and what it takes to observe it is to take the mask
     * away: turning off the index access paths for this session forces the seq scan, after which the
     * delete-and-reinsert above genuinely reverses what an unordered read returns.
     */
    public function testContactOrderIsPersistedRatherThanIncidental(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        // 'B' FIRST, so the stored order disagrees with the ids' natural sort too.
        $client = Client::create(self::CLIENT, 'Acme')
            ->withContact(new Contact(self::CONTACT_B, 'First by order', null, null))
            ->withContact(new Contact(self::CONTACT_A, 'Second by order', null, null));

        self::inTransaction(static fn() => $repository->save($client));

        // MOVE THE FIRST CONTACT TO THE END OF THE HEAP, so an unordered read returns it second.
        //
        // DELETE-then-INSERT rather than an `UPDATE`, and that is not a style choice: an `UPDATE` here was a
        // HOT update -- the new tuple fits on the same page, so the row did NOT move and an unordered read came
        // back in the original order, which is measured rather than assumed (the fixture assertion below is
        // what caught it). A delete followed by a fresh insert appends unambiguously.
        $displaced = self::connection()->fetchAssociative('SELECT * FROM client_contact WHERE id = ?', [self::CONTACT_B]);
        self::assertIsArray($displaced);
        self::connection()->executeStatement('DELETE FROM client_contact WHERE id = ?', [self::CONTACT_B]);
        self::connection()->insert('client_contact', $displaced);

        // TAKE THE MASK AWAY: force a sequential scan, so row order comes from the heap rather than from an
        // index whose own column order happens to be the answer. Restored in a `finally` below, because leaving
        // a session with the index paths disabled would silently change what every later case in this class
        // measures.
        self::connection()->executeStatement('SET enable_indexscan = off');
        self::connection()->executeStatement('SET enable_bitmapscan = off');
        self::connection()->executeStatement('SET enable_indexonlyscan = off');

        try {
            // PROVEN RATHER THAN ASSUMED. This assertion is about the FIXTURE, not the code: unless an
            // unordered read really does come back reversed here, the one below cannot tell a working
            // `ORDER BY` from a missing one, and would be the green-for-the-wrong-reason case this docblock
            // exists to describe.
            self::assertSame(
                [self::CONTACT_A, self::CONTACT_B],
                self::connection()->fetchFirstColumn(
                    'SELECT id FROM client_contact WHERE company_id = ? AND client_id = ?',
                    [self::TENANT_A, self::CLIENT],
                ),
                'the fixture must present an unordered read REVERSED, or this case cannot detect a missing ORDER BY',
            );

            $restored = self::inTransaction(static fn(): ?Client => $repository->find(self::CLIENT));

            self::assertNotNull($restored);
            self::assertSame(
                [self::CONTACT_B, self::CONTACT_A],
                array_map(static fn(Contact $c): string => $c->id, $restored->contacts()),
            );
        } finally {
            self::connection()->executeStatement('RESET enable_indexscan');
            self::connection()->executeStatement('RESET enable_bitmapscan');
            self::connection()->executeStatement('RESET enable_indexonlyscan');
        }
    }

    /**
     * **A RE-SAVE REPLACES THE CONTACT LIST RATHER THAN ACCUMULATING IT.**
     *
     * The aggregate is the unit of truth about its own contacts, so a contact removed from it must disappear
     * from the table. This is what the DELETE-then-INSERT in `save()` is for, and without it the second save
     * would either raise a duplicate-key error or silently leave the removed contact behind.
     */
    public function testResavingReplacesTheContactList(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $client = Client::create(self::CLIENT, 'Acme')
            ->withContact(new Contact(self::CONTACT_A, 'Stays', null, null))
            ->withContact(new Contact(self::CONTACT_B, 'Goes', null, null));
        self::inTransaction(static fn() => $repository->save($client));

        $trimmed = $client->withoutContact(self::CONTACT_B);
        self::inTransaction(static fn() => $repository->save($trimmed));

        $restored = self::inTransaction(static fn(): ?Client => $repository->find(self::CLIENT));

        self::assertNotNull($restored);
        self::assertCount(1, $restored->contacts());
        self::assertSame(self::CONTACT_A, $restored->contacts()[0]->id);
    }

    /** An edit of the parent's own fields is an update, not a second row. */
    public function testResavingUpdatesTheClientRatherThanDuplicatingIt(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $repository->save(Client::create(self::CLIENT, 'Before')));
        self::inTransaction(static fn() => $repository->save(Client::create(self::CLIENT, 'After')));

        $restored = self::inTransaction(static fn(): ?Client => $repository->find(self::CLIENT));

        self::assertNotNull($restored);
        self::assertSame('After', $restored->name());
        self::assertSame(
            1,
            (int) self::connection()->fetchOne('SELECT count(*) FROM client'),
            'a re-save must update the row, not insert a second one',
        );
    }

    /** An address can be CLEARED, and clearing it must null every part rather than leaving a half one behind. */
    public function testClearingAnAddressRemovesEveryPart(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $client = Client::create(self::CLIENT, 'Acme')
            ->withAddress(new PostalAddress('12 Rue de la Paix', 'Bâtiment C', '75002', 'Paris', 'FR'));
        self::inTransaction(static fn() => $repository->save($client));
        self::inTransaction(static fn() => $repository->save($client->withAddress(null)));

        $restored = self::inTransaction(static fn(): ?Client => $repository->find(self::CLIENT));

        self::assertNotNull($restored);
        self::assertNull($restored->address());

        // AND AT THE COLUMN LEVEL, because `addressFrom()` returning null would also be consistent with a row
        // that still held a stray postcode -- which the `client_address_is_whole` CHECK forbids, so a leftover
        // would be a constraint violation on some LATER write rather than here. Asserted directly.
        $row = self::connection()->fetchAssociative('SELECT * FROM client WHERE id = ?', [self::CLIENT]);
        self::assertIsArray($row);
        self::assertNull($row['address_postcode'], 'clearing the address must clear the optional parts too');
        self::assertNull($row['address_line_2']);
    }

    /** Another tenant's client is not findable, and the answer is the same as for one that does not exist. */
    public function testAnotherTenantsClientIsNotFound(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $repository->save(Client::create(self::CLIENT, 'Acme')));

        $asB = self::repositoryFor(self::TENANT_B);

        self::assertNull(self::inTransaction(static fn(): ?Client => $asB->find(self::CLIENT)));
    }

    public function testAnUnknownClientIsNotFound(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        self::assertNull(self::inTransaction(
            static fn(): ?Client => $repository->find('11111111-1111-4111-8111-111111111111'),
        ));
    }

    /** An ill-formed id is refused BEFORE it reaches a query, where PostgreSQL would raise a type error. */
    public function testAnIllFormedIdIsRefusedBeforeItReachesTheDatabase(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical lowercase-hyphenated UUID');

        self::inTransaction(static fn(): ?Client => $repository->find('not-a-uuid'));
    }

    /**
     * **BOTH METHODS REFUSE OUTSIDE A TRANSACTION**, which is the fail-closed half of the tenancy design.
     *
     * Without it a read outside a transaction would see nothing — the binding is transaction-local — and report
     * "no such client" for a client that exists. `CLAUDE.md` § Gotchas 2026-08-07 records that downgrade
     * costing three commits.
     */
    public function testReadingOutsideATransactionIsRefused(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside a transaction');

        $repository->find(self::CLIENT);
    }

    public function testWritingOutsideATransactionIsRefused(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside a transaction');

        $repository->save(Client::create(self::CLIENT, 'Acme'));
    }

    /** No tenant bound is refused too — the boundary rule that no tenant-less path may hydrate an aggregate. */
    public function testReadingWithNoTenantBoundIsRefused(): void
    {
        $repository = new DoctrineClientRepository(self::connection(), new InMemoryTenantContext());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no tenant bound');

        self::inTransaction(static fn(): ?Client => $repository->find(self::CLIENT));
    }

    /**
     * **DELETING A CLIENT TAKES ITS CONTACTS WITH IT**, through the composite FK's ON DELETE CASCADE.
     *
     * There is no `delete()` on the port yet, so this is asserted at the schema level — which is where the
     * guarantee lives anyway. Without the cascade, deleting a client would leave orphan contacts that the
     * aggregate could never load and that would collide with the next client to reuse the id.
     */
    public function testDeletingAClientCascadesToItsContacts(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        $client = Client::create(self::CLIENT, 'Acme')
            ->withContact(new Contact(self::CONTACT_A, 'Amine', null, null));
        self::inTransaction(static fn() => $repository->save($client));

        self::connection()->executeStatement('DELETE FROM client WHERE id = ?', [self::CLIENT]);

        self::assertSame(
            0,
            (int) self::connection()->fetchOne('SELECT count(*) FROM client_contact'),
            'the contacts must go with their client',
        );
    }

    /**
     * A repository bound to one tenant, with the session GUC set to match.
     *
     * SESSION-scoped here (`set_config(..., false)`) rather than transaction-local, matching
     * {@see DoctrineInvoiceRepositoryTest}: these cases open several short transactions against one connection.
     * Production must use the transaction-local form — a session-scoped value leaks to whoever gets the pooled
     * connection next, which is what `PostgresRowLevelSecurityIsolation::bind()` exists to avoid. Legitimate
     * here only because this connection is not pooled and is discarded with the class.
     */
    private static function repositoryFor(string $tenant): DoctrineClientRepository
    {
        self::connection()->executeStatement(
            \sprintf("SELECT set_config('%s', ?, false)", PostgresRowLevelSecurityIsolation::TENANT_SETTING),
            [$tenant],
        );

        return new DoctrineClientRepository(
            self::connection(),
            InMemoryTenantContext::forTenant(TenantId::fromString($tenant)),
        );
    }

    /**
     * The OWNER connection. `client` is `FORCE ROW LEVEL SECURITY`, so the owner is policed too — the binding
     * above is what makes anything visible. The owner rather than the runtime role because the probe database
     * is created fresh and the runtime role's per-database grants are provisioned only for `twes_in_test`;
     * tenant ISOLATION is `BehaviouralIsolationTest`'s subject and is deliberately not re-proven here.
     */
    private static function connection(): Connection
    {
        if (null === self::$connection) {
            try {
                self::$connection = DriverManager::getConnection([
                    'driver' => 'pdo_pgsql',
                    'host' => self::host(),
                    'port' => (int) self::port(),
                    'dbname' => self::DATABASE,
                    'user' => self::ownerRole(),
                    'password' => self::ownerPassword(),
                ]);
                self::$connection->executeQuery('SELECT 1');
            } catch (\Doctrine\DBAL\Exception $exception) {
                self::fail('Could not connect to the probe database: ' . $exception->getMessage());
            }
        }

        return self::$connection;
    }

    /**
     * Run one callable inside a real transaction, so the repository's own refusal is satisfied.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private static function inTransaction(callable $work): mixed
    {
        $connection = self::connection();
        $connection->beginTransaction();

        try {
            $result = $work();
            $connection->commit();

            return $result;
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }
    }
}
