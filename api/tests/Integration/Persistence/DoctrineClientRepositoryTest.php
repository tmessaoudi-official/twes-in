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
use Twes\Domain\Document\DocumentState;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\VatRoundingPoint;
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
    private const DOCUMENT = '0199a5b2-0000-7000-8000-00000000d001';

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
        self::emptyTheFixture();
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
     * **THE FIXTURE CLEANUP ITSELF IS PROVEN TO WORK, and it is here because a `setUp()` cannot prove itself.**
     *
     * The first version of `emptyTheFixture()` issued `DELETE FROM client`, and it was very nearly vacuous:
     * both tables are `FORCE ROW LEVEL SECURITY`, so the OWNER is policed too, and the canonical policy compares
     * against `twes.tenant_id`. Before any case has bound a tenant the setting is unset, the predicate is NULL,
     * and the DELETE matches ZERO rows; worse, after a case that leaves the session bound to TENANT_B the next
     * `setUp()` deletes B's rows and leaves A's standing. The suite was green only because of the order the
     * cases happen to be DECLARED in — which is exactly the order-dependent fixture `CLAUDE.md` § Gotchas
     * 2026-08-07 records, inside the method whose docblock claimed to prevent it.
     *
     * Proving it needs the cleanup called from a case rather than from `setUp()`, because `setUp()` runs before
     * the assertions and the only way to observe it from a later case is to depend on declaration order — the
     * very thing being fixed. So the body is a named method, and this case drives it directly: leave a row owned
     * by A behind, bind the session to B, empty, then rebind to A and look. **The rebinding is load-bearing —
     * counting while bound to B would report 0 whether or not A's row survived**, which is how the original
     * defect stayed invisible.
     */
    public function testTheFixtureIsEmptiedWhateverTenantTheSessionIsBoundTo(): void
    {
        $a = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $a->save(Client::create(self::CLIENT, 'Left behind')));
        self::repositoryFor(self::TENANT_B);

        self::emptyTheFixture();

        self::repositoryFor(self::TENANT_A);
        self::assertSame(
            0,
            (int) self::connection()->fetchOne('SELECT count(*) FROM client'),
            'the cleanup must empty the table whatever tenant the session was bound to when it ran',
        );
    }

    /**
     * **THE CLEANUP SURVIVES A DOCUMENT POINTING AT THE CLIENT — the detector for the defect, written first.**
     *
     * With the hand-written `TRUNCATE client_contact, client` in place this case fails with
     * *"cannot truncate a table referenced in a foreign key constraint"*, and so does every other case in this
     * class, because the statement is in `setUp()`. That is how the `document.client_id` foreign key announced
     * itself: sixteen failures in a suite that has nothing to do with invoices.
     *
     * A RAW `INSERT` rather than the invoice repository, deliberately. What is under test is the FIXTURE, and
     * routing it through another aggregate's repository would make this case fail whenever THAT changed — it
     * would be a test of the invoice write path wearing a cleanup test's name. The row only has to exist and
     * point at the client; it does not have to be an invoice anybody would recognise.
     *
     * A DRAFT, because `document_client_required_once_issued` permits a client on a draft and demands one on an
     * issued document. Either state would satisfy the foreign key; the draft is the one that says nothing about
     * the issue guard, which is not this case's subject.
     */
    public function testTheFixtureIsEmptiedEvenWhenADocumentReferencesTheClient(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $repository->save(Client::create(self::CLIENT, 'Referenced')));

        self::connection()->executeStatement(
            'INSERT INTO document (company_id, id, type, state, currency, vat_rounding_point, client_id) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                self::TENANT_A,
                self::DOCUMENT,
                DocumentType::Invoice->value,
                DocumentState::Draft->value,
                'TND',
                VatRoundingPoint::PerRateGroup->value,
                self::CLIENT,
            ],
        );

        self::emptyTheFixture();

        self::assertSame(
            0,
            (int) self::connection()->fetchOne('SELECT count(*) FROM client'),
            'the cleanup must empty the client table even while a document references it',
        );
        self::assertSame(
            0,
            (int) self::connection()->fetchOne('SELECT count(*) FROM document'),
            'the referencing document goes too — that is the cost of truncating the closure, and it is stated '
            . 'here so a reader of this class knows the document tables are emptied by its setUp()',
        );
    }

    /**
     * Empty the fixture, whatever the session is bound to.
     *
     * `TRUNCATE` rather than `DELETE` for the reason the case above proves: TRUNCATE is not subject to row-level
     * security, and the owner owns these tables, so it genuinely empties them.
     *
     * **THE TABLE SET IS DERIVED FROM THE CATALOGUE RATHER THAN WRITTEN DOWN, and the written-down version was
     * already wrong the day it was written.** PostgreSQL refuses to `TRUNCATE` a table while another table has a
     * foreign key into it unless every referencing table is truncated in the SAME statement — so the set is the
     * transitive closure of *references `client`*, not a pair of names. This method read
     * `TRUNCATE client_contact, client` until `document.client_id` landed, at which point the closure grew by
     * THREE tables: `document`, and `document_line` and `document_charge` behind it. Only the first of those
     * three is visible from the change that caused it, which is exactly why a hand-written list loses.
     *
     * **`CASCADE` IS DELIBERATELY NOT USED, and avoiding it is most of the point.** On `TRUNCATE`, `CASCADE`
     * does not mean what it means on a foreign key: it does not delete dependent ROWS, it truncates the
     * referencing TABLES, silently and without naming them. It would compute this same closure and empty every
     * table in it with nothing in the source saying which. The failure mode is a suite that quietly wipes a
     * table nobody in this file has ever heard of.
     */
    private static function emptyTheFixture(): void
    {
        self::connection()->executeStatement(
            'TRUNCATE ' . implode(', ', self::everythingThatReferences('client')),
        );
    }

    /**
     * Every table that must be truncated alongside `$table`, `$table` itself included.
     *
     * `UNION` rather than `UNION ALL` is load-bearing: it de-duplicates, which is what terminates the recursion
     * on a self-referencing foreign key (`conrelid = confrelid`) instead of looping forever.
     *
     * The names come back through `regclass`, so PostgreSQL has already schema-qualified and quoted anything
     * that needs it — they are safe to interpolate, and there is no other way to spell a table list in a
     * `TRUNCATE`, which takes no parameters.
     *
     * @return list<string>
     */
    private static function everythingThatReferences(string $table): array
    {
        /** @var list<string> */
        return self::connection()->fetchFirstColumn(
            <<<'SQL'
                WITH RECURSIVE referencing (oid) AS (
                    SELECT to_regclass(?)::oid
                    UNION
                    SELECT fk.conrelid
                    FROM pg_constraint AS fk
                    JOIN referencing ON fk.confrelid = referencing.oid
                    WHERE fk.contype = 'f'
                )
                SELECT oid::regclass::text FROM referencing ORDER BY 1
                SQL,
            [$table],
        );
    }

    /**
     * **`client_address_is_whole` IS PROVEN TO FIRE, rather than merely never violated.**
     *
     * Every other case in this class stores either a WHOLE address or NONE, because that is all the domain type
     * can express — so the CHECK was satisfied by every row the suite writes and a polarity typo in its
     * five-NULL-or-three-NOT-NULL expression would have been invisible with the whole suite green. That is the
     * detector-before-the-repair standard `CLAUDE.md` § Gotchas 2026-08-07 sets for an infra change, and the
     * 2026-07-30 rule it restates: a constraint nothing violates is indistinguishable from one that permits
     * everything. `DoctrineClientRepository::addressFrom()`'s docblock cites this CHECK as the reason a half
     * address cannot be stored, which until now was an unverified claim about a control.
     *
     * A RAW INSERT rather than the repository, deliberately: the repository cannot produce this row, and the
     * constraint exists precisely for the writer that is not it — a migration, a `psql` session, a future
     * importer.
     */
    public function testAHalfAddressIsRefusedByTheSchema(): void
    {
        self::repositoryFor(self::TENANT_A);

        self::assertRefusedBy(
            'client_address_is_whole',
            'INSERT INTO client (company_id, id, name, address_line_1, address_city) VALUES (?, ?, ?, ?, ?)',
            [self::TENANT_A, self::CLIENT, 'Half an address', '12 Rue de la Paix', 'Paris'],
        );
    }

    /**
     * **`client_country_code_is_alpha_2` IS PROVEN TO FIRE**, for the same reason as the case above.
     *
     * `PostalAddress` REFUSES a lowercase code rather than upcasing it, so no route through the domain can
     * store `fr` — which is exactly why the schema half needs its own evidence. The column is `CHAR(2)`, so a
     * wrong-LENGTH code is already refused by the type; what this constraint adds is the ALPHABET and the CASE,
     * and only a direct write can demonstrate it.
     */
    public function testALowercaseCountryCodeIsRefusedByTheSchema(): void
    {
        self::repositoryFor(self::TENANT_A);

        self::assertRefusedBy(
            'client_country_code_is_alpha_2',
            'INSERT INTO client (company_id, id, name, address_line_1, address_city, address_country_code) '
            . 'VALUES (?, ?, ?, ?, ?, ?)',
            [self::TENANT_A, self::CLIENT, 'Acme', '12 Rue de la Paix', 'Paris', 'fr'],
        );
    }

    /**
     * Assert that a statement is refused BY A NAMED CONSTRAINT, not merely that it fails.
     *
     * The name is asserted rather than the failure, because `CLAUDE.md` § Gotchas 2026-07-29 records a meta-gate
     * reporting 33/33 for a gate that detected nothing: a crash and a detection are indistinguishable from an
     * exit code alone. Here the difference is real — a typo'd column name, a row-level-security refusal and the
     * CHECK all raise a `Doctrine\DBAL\Exception`, and only one of them is the thing being proven.
     *
     * @param list<string> $parameters
     */
    private static function assertRefusedBy(string $constraint, string $sql, array $parameters): void
    {
        try {
            self::connection()->executeStatement($sql, $parameters);
        } catch (\Doctrine\DBAL\Exception $refusal) {
            self::assertStringContainsString(
                $constraint,
                $refusal->getMessage(),
                \sprintf('the write must be refused by %s specifically, not by something else', $constraint),
            );

            return;
        }

        self::fail(\sprintf('the schema accepted a row that %s exists to refuse', $constraint));
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
