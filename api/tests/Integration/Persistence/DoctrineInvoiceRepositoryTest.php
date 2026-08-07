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
use Twes\Domain\Document\DocumentIdentity;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\DocumentNumber;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\Infrastructure\Persistence\Doctrine\DoctrineInvoiceRepository;
use Twes\Infrastructure\Persistence\Doctrine\InvoiceMapper;
use Twes\Infrastructure\Persistence\Doctrine\RowHydrator;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Tests\Integration\Tenancy\MigratedProbeDatabase;

/**
 * THE REPOSITORY, AGAINST A REAL MIGRATED SCHEMA. The other half of the immutability ruling's price.
 *
 * `InvoiceMapperTest` is the in-memory round-trip contract: `toRows()` → `toAggregate()` must be an identity. That
 * test is deliberately database-free, and it is therefore blind to everything a real column does — round 22 found it
 * asserting a quantity STRING with a comment claiming that pinned scale, which is false the moment
 * `NUMERIC(21,6)` returns `'2.000000'` for `'2'`. This is the test that crosses the database.
 *
 * So the two halves assert different things on purpose: the mapper must not RENORMALISE (string identity, enforced
 * there), and a round trip through PostgreSQL must not change the NUMBER (numeric comparison, enforced here). A
 * single test cannot do both, and conflating them is what produced the wrong assertion.
 */
#[CoversClass(DoctrineInvoiceRepository::class)]
#[CoversClass(RowHydrator::class)]
final class DoctrineInvoiceRepositoryTest extends TestCase
{
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_invoice_repository_probe';
    private const TENANT_A = '0199a5b2-0000-7000-8000-0000000002aa';
    private const TENANT_B = '0199a5b2-0000-7000-8000-0000000002bb';
    private const DOCUMENT = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

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
     * A DRAFT WITH LINES AND CHARGES SURVIVES A REAL ROUND TRIP.
     *
     * TND on purpose: three decimal places, so a two-decimal assumption anywhere in the chain shows up here rather
     * than in production. `CLAUDE.md` § Architecture — *"a 2-decimal assumption is a bug for the default currency,
     * not an edge case"*.
     */
    public function testADraftSurvivesARoundTripThroughRealColumns(): void
    {
        $tnd = Currency::of('TND');
        $invoice = Invoice::draft($tnd)
            ->withLine(new DocumentLine('2', Money::of('1.234', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('1', Money::of('0.500', $tnd), Rate::fromPercentage('7')))
            ->withFixedCharge(new FixedCharge('stamp_duty', Money::of('0.100', $tnd)));

        $repository = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $repository->save(self::identity(), $invoice));

        $restored = $repository->find(self::DOCUMENT);

        self::assertNotNull($restored, 'the document must be readable back');
        self::assertSame(self::DOCUMENT, $restored->identity->id, 'document id');
        self::assertSame(DocumentType::Invoice, $restored->identity->type, 'document type');
        self::assertSame(VatRoundingPoint::PerRateGroup, $restored->identity->vatRoundingPoint, 'rounding point');
        self::assertSame('TND', $restored->invoice->currency()->code(), 'currency');
        self::assertNull($restored->invoice->number(), 'a draft carries no number');

        // POSITION ORDER, not arrival order. No `ORDER BY` is issued — the mapper sorts, and this is what proves the
        // repository does not silently depend on PostgreSQL returning rows in insertion order.
        $quantities = array_map(static fn(DocumentLine $l): string => $l->quantity(), $restored->invoice->lines());
        self::assertCount(2, $quantities, 'both lines came back');
        // NUMERICALLY, EVEN FOR THE ORDER ASSERTION. The first version of this compared `['2', '1']` by string and
        // FAILED with `['2.000000', '1.000000']` — the exact defect this class's docblock describes two paragraphs
        // up, committed in the same file that documents it. Kept as a note because being able to state a rule is
        // evidently not the same as applying it: `quantity` is `NUMERIC(21,6)`, so the scale comes back from the
        // column and not from what was written.
        self::assertSame(0, bccomp('2', $quantities[0], 6), 'first line, in persisted position order');
        self::assertSame(0, bccomp('1', $quantities[1], 6), 'second line, in persisted position order');
        self::assertSame('stamp_duty', $restored->invoice->fixedCharges()[0]->label(), 'the charge');

        // NUMERICALLY, NOT BY STRING. `NUMERIC(21,6)` returns `'2.000000'` for `'2'` — the same number, a different
        // string. Asserting the string here would fail on correct code; asserting it in the in-memory mapper test is
        // right, because there nothing re-scales. See this class's docblock.
        self::assertSame(
            0,
            bccomp('1.234', $restored->invoice->lines()[0]->unitNet()->amount(), 6),
            'the unit price is the same NUMBER after a real NUMERIC round trip',
        );
        // And the money that must be EXACT to the millime: 0.100 TND is Tunisia's stamp duty, and § Architecture
        // requires it to represent exactly rather than approximately.
        self::assertSame(
            0,
            bccomp('0.100', $restored->invoice->fixedCharges()[0]->amount()->amount(), 6),
            'a 3-decimal amount survives exactly',
        );
    }

    /**
     * AN ISSUED DOCUMENT KEEPS ITS NUMBER, BOTH HALVES, THROUGH THE DATABASE.
     *
     * The rendered string and the sequence are separate columns with a CHECK constraint pairing them, so this is the
     * first test that exercises the pair through a real INSERT rather than in memory.
     */
    public function testAnIssuedDocumentKeepsBothHalvesOfItsNumber(): void
    {
        $tnd = Currency::of('TND');
        $invoice = Invoice::draft($tnd)
            ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
            ->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41));

        $repository = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $repository->save(self::identity(), $invoice));

        $restored = $repository->find(self::DOCUMENT);
        self::assertNotNull($restored);

        $number = $restored->invoice->number();
        self::assertNotNull($number, 'an issued document comes back numbered');
        self::assertSame(41, $number->sequence(), 'the sequence, which identifies');
        self::assertSame('0000041', $number->number(), 'the rendered string, which is what a client holds');
    }

    /**
     * SAVING TWICE UNDER ONE IDENTITY REPLACES, and the CHILD ROWS ARE REWRITTEN rather than accumulated.
     *
     * This is the case the whole DBAL write path exists for. Doing it through the UnitOfWork raises
     * `EntityIdentityCollisionException` before any SQL — removing the line at position 0 and persisting a new one at
     * position 0 collides in the identity map. [Verified against this schema.] So this test is what proves the chosen
     * write path actually delivers what the ORM could not.
     */
    public function testSavingTwiceReplacesTheChildRowsRatherThanAccumulatingThem(): void
    {
        $tnd = Currency::of('TND');
        $repository = self::repositoryFor(self::TENANT_A);

        $three = Invoice::draft($tnd)
            ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('2', Money::of('2.000', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('3', Money::of('3.000', $tnd), Rate::fromPercentage('19')));
        self::inTransaction(static fn() => $repository->save(self::identity(), $three));

        // ONE line, at position 0 — the exact PK the previous save already used.
        $one = Invoice::draft($tnd)
            ->withLine(new DocumentLine('9', Money::of('9.000', $tnd), Rate::fromPercentage('7')));
        self::inTransaction(static fn() => $repository->save(self::identity(), $one));

        $restored = $repository->find(self::DOCUMENT);
        self::assertNotNull($restored);
        self::assertCount(1, $restored->invoice->lines(), 'the old lines are GONE, not merged');
        self::assertSame(
            0,
            bccomp('9', $restored->invoice->lines()[0]->quantity(), 6),
            'the surviving line is the new one — compared numerically, per the note above',
        );

        // Directly, because a count through the aggregate could be masked by the mapper: assert the table.
        self::assertSame(
            1,
            (int) self::connection()->fetchOne(
                'SELECT count(*) FROM document_line WHERE company_id = ? AND document_id = ?',
                [self::TENANT_A, self::DOCUMENT],
            ),
            'exactly one child row in the table',
        );
    }

    /** A document belonging to another tenant is NOT FOUND, which is the only honest answer under row-level security. */
    public function testAnotherTenantsDocumentIsNotFound(): void
    {
        $tnd = Currency::of('TND');
        $invoice = Invoice::draft($tnd)
            ->withLine(new DocumentLine('1', Money::of('1.000', $tnd), Rate::fromPercentage('19')));

        $ownersRepository = self::repositoryFor(self::TENANT_A);
        self::inTransaction(static fn() => $ownersRepository->save(self::identity(), $invoice));

        // Same id, different tenant. The document exists; this tenant may not see it.
        self::assertNull(
            self::repositoryFor(self::TENANT_B)->find(self::DOCUMENT),
            'another tenant must get null — not an error naming the document, which would itself be a leak',
        );
    }

    /**
     * SAVING WITH NO TENANT BOUND IS REFUSED — Wave 1's boundary rule, asserted rather than described.
     *
     * Both directions of the rule are covered: this one and the read below. Without both, a repository that enforced
     * it on one path only would be the shape § Gotchas records for the handoff hook — *"a guard on one write path is
     * not a guard"*.
     */
    public function testSavingWithNoTenantBoundIsRefused(): void
    {
        $repository = new DoctrineInvoiceRepository(
            self::connection(),
            InMemoryTenantContext::empty(),
            new InvoiceMapper(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to save document');

        self::inTransaction(static fn() => $repository->save(
            self::identity(),
            Invoice::draft(Currency::of('TND')),
        ));
    }

    /** The read half of the same boundary rule. */
    public function testReadingWithNoTenantBoundIsRefused(): void
    {
        $repository = new DoctrineInvoiceRepository(
            self::connection(),
            InMemoryTenantContext::empty(),
            new InvoiceMapper(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Refusing to read document');

        $repository->find(self::DOCUMENT);
    }

    /**
     * SAVING OUTSIDE A TRANSACTION IS REFUSED, because a gapless number and its document must commit together.
     *
     * The alternative — opening a transaction here — would make the atomic case unwritable, and the failure mode is
     * a permanent hole in an invoice sequence, which § Gotchas 2026-07-31 records as what a tax authority reads as a
     * suppressed sale.
     */
    public function testSavingOutsideATransactionIsRefused(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside a transaction');

        $repository->save(self::identity(), Invoice::draft(Currency::of('TND')));
    }

    /** An ill-formed id is refused before it reaches a query, by the same rule `DocumentIdentity` enforces. */
    public function testAnIllFormedIdIsRefusedBeforeItReachesAQuery(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\InvalidArgumentException::class);
        // Uppercase, which is a valid UUID to most readers and a DIFFERENT STRING to a key comparison.
        $repository->find(strtoupper(self::DOCUMENT));
    }

    /**
     * A DOCUMENT OF ANOTHER TYPE IS NOT FOUND BY THE INVOICE REPOSITORY, even under the right tenant and the right id.
     *
     * Without the `type` predicate this method returned any document sharing the id as a `PersistedInvoice`, so
     * `GET /api/invoices/{quoteId}` would have served a quote rendered as an invoice — and issuing it would have taken
     * a number from the INVOICE sequence for a document of another type. Unreachable today because Wave 1 creates no
     * other type, which is exactly why it had to be closed before one exists.
     *
     * The row is inserted directly rather than through `save()`, because the write path already refuses a non-invoice
     * identity — see the case below — so this is the only way to produce the state being tested.
     */
    public function testADocumentOfAnotherTypeIsNotFoundByTheInvoiceRepository(): void
    {
        $quoteId = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
        $repository = self::repositoryFor(self::TENANT_A);

        self::connection()->executeStatement(
            'INSERT INTO document (company_id, id, type, state, currency, vat_rounding_point)'
            . ' VALUES (?, ?, ?, ?, ?, ?)',
            [self::TENANT_A, $quoteId, DocumentType::Quote->value, 'draft', 'TND', 'per_rate_group'],
        );

        self::assertNull(
            $repository->find($quoteId),
            'a quote must not come back from the INVOICE repository — the port is contracted to find invoices',
        );
    }

    /**
     * And the write half: saving somebody else's document type is OUR bug, so it is a `\LogicException`.
     *
     * **The refusal belongs to `InvoiceMapper` and this test found that out the useful way.** A guard was added to
     * `save()` first; a mutant deleting it reported that the mapper had refused anyway, with a *better* message —
     * naming the consequence, that an `Invoice` written under another type files its number in that type's sequence and
     * leaves a permanent hole in the invoice one. So the repository's copy was deleted rather than kept: two
     * definitions of one rule is what this repository has recorded drifting, and the surviving definition is the one
     * that explains itself.
     *
     * Asserted from the repository rather than by calling the mapper directly, because what must hold is that the
     * REPOSITORY refuses — wherever the check physically lives.
     */
    public function testSavingADocumentOfAnotherTypeIsRefused(): void
    {
        $repository = self::repositoryFor(self::TENANT_A);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('was handed a quote identity');

        self::inTransaction(static fn() => $repository->save(
            new DocumentIdentity(self::DOCUMENT, DocumentType::Quote, VatRoundingPoint::PerRateGroup),
            Invoice::draft(Currency::of('TND')),
        ));
    }

    private static function identity(): DocumentIdentity
    {
        return new DocumentIdentity(self::DOCUMENT, DocumentType::Invoice, VatRoundingPoint::PerRateGroup);
    }

    /**
     * Run one closure inside a transaction, which is what the repository requires of its caller.
     *
     * A helper rather than repetition, and it commits: these cases assert on state read back afterwards, so a
     * rolling-back helper would make every one of them vacuous.
     */
    private static function inTransaction(callable $work): void
    {
        $connection = self::connection();
        $connection->beginTransaction();

        try {
            $work();
            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }
    }

    /**
     * A repository bound to one tenant, with the session GUC set to match.
     *
     * The binding is SESSION-scoped here (`set_config(..., false)`) rather than transaction-local, because these
     * cases read outside the transaction they wrote in. Production must use the transaction-local form — a
     * session-scoped value leaks to whoever gets the pooled connection next, which is exactly what
     * `PostgresRowLevelSecurityIsolation::bind()` exists to avoid. Legitimate here only because this connection is
     * not pooled and is discarded with the class.
     */
    private static function repositoryFor(string $tenant): DoctrineInvoiceRepository
    {
        self::connection()->executeStatement(
            \sprintf("SELECT set_config('%s', ?, false)", PostgresRowLevelSecurityIsolation::TENANT_SETTING),
            [$tenant],
        );

        return new DoctrineInvoiceRepository(
            self::connection(),
            InMemoryTenantContext::forTenant(TenantId::fromString($tenant)),
            new InvoiceMapper(),
        );
    }

    /**
     * The OWNER connection. `document` is `FORCE ROW LEVEL SECURITY`, so the owner is policed too — the binding above
     * is what makes anything visible. The owner rather than the runtime role because the probe database is created
     * fresh and the runtime role's per-database grants are provisioned only for `twes_in_test`; tenant ISOLATION is
     * `BehaviouralIsolationTest`'s subject and is deliberately not re-proven here.
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
}
