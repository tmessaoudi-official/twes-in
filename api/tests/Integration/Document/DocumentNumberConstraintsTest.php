<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration\Document;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Tests\Integration\Tenancy\MigratedProbeDatabase;

/**
 * THE `document` NUMBER CONSTRAINTS, exercised against a REAL migrated schema.
 *
 * **Why this file exists at all: the migrations' CHECK constraints were asserted by nothing.** `document_type_is_known`,
 * `document_state_is_known`, `document_vat_rounding_point_is_known`, `document_number_is_positive` and
 * `document_number_sequence_starts_at_one` all shipped with `Version20260801120000` and no test named any of them. The
 * risk is lower than the usual unenforced-control shape recorded in `CLAUDE.md` § Gotchas — PostgreSQL cannot quietly
 * stop applying a constraint, so it can only be lost by a future migration dropping it — but "lower" is not "absent",
 * and a constraint nobody asserts is one a later `diff`-generated migration can drop while every test stays green.
 *
 * It is written now because `Version20260806180000` adds two constraints whose whole purpose is to be load-bearing:
 * the number's two halves must be absent or present TOGETHER, and the rendered string must be digits. Those are
 * relationships a `NOT NULL` and a column type cannot express, so if they are wrong the column silently stops
 * guaranteeing the thing it was added for. The pre-existing number constraint is covered here too, because it is
 * about the same column pair and leaving it out would be the partial-coverage shape this repo keeps finding.
 *
 * **NOT scoped to what the mapper can produce, deliberately.** Every refusal here is also refused in PHP by
 * `InvoiceMapper::numberFrom()`, and that is not duplication: the mapper protects a CALLER by giving it a message it
 * can act on, and the constraint protects the DATA from a direct `UPDATE`, a `psql` session, a reporting job or a
 * future migration — none of which goes through PHP at all. `CLAUDE.md` § Gotchas records the same reasoning for
 * `document_number_sequence_starts_at_one`: *"the domain refusal gives a caller a usable error, and the constraint
 * stops a direct UPDATE writing state the domain could not then read back."* This is the second half of that pair.
 *
 * A probe database rather than the shared test one, via the same trait `SchemaTenancyGateTest` and
 * `BehaviouralIsolationTest` use: these cases INSERT rows and must not leave any behind.
 */
#[CoversNothing]
final class DocumentNumberConstraintsTest extends TestCase
{
    use MigratedProbeDatabase;

    private const DATABASE = 'twes_document_constraints_probe';

    private static ?\PDO $owner = null;

    public static function setUpBeforeClass(): void
    {
        self::createMigratedProbeDatabase(self::DATABASE);
    }

    public static function tearDownAfterClass(): void
    {
        self::$owner = null;
        self::dropProbeDatabase(self::DATABASE);
    }

    /**
     * The shapes the constraints must REFUSE.
     *
     * Each case is a `(number, number_rendered)` pair plus the constraint expected to name it. Asserting the
     * CONSTRAINT NAME rather than only that an exception was raised is the discipline `CLAUDE.md` § Gotchas records
     * repeatedly: a `NOT NULL` violation on an unrelated column, a typo'd table name and the constraint firing are
     * indistinguishable by "something threw", and the first two would let a case pass while proving nothing.
     *
     * @return iterable<string, array{int|null, string|null, string}>
     */
    public static function refusedNumberShapes(): iterable
    {
        yield 'a sequence with no rendered string — the half the mapper would have to re-render from a guess' => [
            41, null, 'document_number_halves_are_paired',
        ];

        yield 'a rendered string with no sequence — a number that cannot be ordered by or made unique' => [
            null, '0000041', 'document_number_halves_are_paired',
        ];

        // The pairing constraint passes this one (both halves present), so it isolates the digits rule.
        yield 'a rendered string carrying a prefix, which stops it being sortable or parseable' => [
            41, 'INV-0041', 'document_number_rendered_is_digits',
        ];

        // `+` rather than `*` in the pattern is what refuses this. An empty rendered number is a document that
        // prints nothing where its number belongs, and it is the value a careless `COALESCE(…, '')` produces.
        yield 'an EMPTY rendered string, which a `*` quantifier would have admitted' => [
            41, '', 'document_number_rendered_is_digits',
        ];

        yield 'a negative-looking rendered number' => [
            41, '-000041', 'document_number_rendered_is_digits',
        ];

        // PRE-EXISTING, from Version20260801120000, and asserted here for the first time. `DocumentNumber` refuses a
        // sequence below 1 because zero is what an uninitialised counter holds.
        yield 'sequence zero, which is what an uninitialised counter holds' => [
            0, '0000000', 'document_number_is_positive',
        ];
    }

    #[DataProvider('refusedNumberShapes')]
    public function testTheSchemaRefusesAnUnusableNumber(
        ?int $number,
        ?string $rendered,
        string $expectedConstraint,
    ): void {
        try {
            self::insertDocument($number, $rendered);
            self::fail(\sprintf(
                'The schema accepted (number=%s, number_rendered=%s). Expected %s to refuse it.',
                var_export($number, true),
                var_export($rendered, true),
                $expectedConstraint,
            ));
        } catch (\PDOException $exception) {
            self::assertStringContainsString(
                $expectedConstraint,
                $exception->getMessage(),
                'the constraint that refused it, by name — "something threw" would pass on an unrelated violation',
            );
        }
    }

    /**
     * The shapes the constraints must ACCEPT — the half that stops this test passing vacuously.
     *
     * A constraint pair that refuses everything would satisfy every case above. A draft with neither half and an
     * issued document with both are the two states the aggregate actually has, so they must both insert.
     *
     * @return iterable<string, array{int|null, string|null}>
     */
    public static function acceptedNumberShapes(): iterable
    {
        yield 'a draft: neither half' => [null, null];
        yield 'an issued document: both halves, padded' => [41, '0000041'];
        yield 'a sequence that outran its padding, which format() grows rather than truncating' => [12345, '12345'];
        yield 'width 1, the honest minimum a pattern may be configured to' => [7, '7'];
        // 19 digits: PHP_INT_MAX, the widest sequence the domain admits, inside the VARCHAR(20) bound.
        yield 'the widest sequence the domain admits' => [\PHP_INT_MAX, (string) \PHP_INT_MAX];
    }

    #[DataProvider('acceptedNumberShapes')]
    public function testTheSchemaAcceptsEveryStateTheAggregateHas(?int $number, ?string $rendered): void
    {
        [$company, $id] = self::insertDocument($number, $rendered);

        $statement = self::owner()->prepare(
            'SELECT number, number_rendered FROM document WHERE company_id = ? AND id = ?',
        );
        $statement->execute([$company, $id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row, 'the row must be readable back');
        // COMPARED AS AN INT, and the first version of this line asserted a STRING with a confident comment
        // explaining that PDO does not narrow a PostgreSQL `bigint` because it can exceed PHP's int. That was an
        // unverified claim and it is false here: pdo_pgsql on PHP 8.5 returns a native int, including for
        // `PHP_INT_MAX`. [Verified: the string assertion failed with `Failed asserting that 41 is identical to
        // '41'` for all four issued cases.] Kept as a note rather than silently corrected, because the reasoning
        // was plausible and someone will reach for it again.
        self::assertSame($number, null === $row['number'] ? null : (int) $row['number'], 'the sequence');
        self::assertSame($rendered, $row['number_rendered'], 'the rendered string');
    }

    /**
     * THE COLUMN IS WIDE ENOUGH FOR EVERY PATTERN THE DOMAIN PERMITS, AND NO WIDER.
     *
     * `VARCHAR(20)` matches `NumberPattern::MAX_WIDTH`. Both directions matter: a narrower column would reject a
     * legally configured pattern at `INSERT` time — after the number had been allocated, so the sequence is burnt
     * and the gapless guarantee is broken — and a wider one would admit a string `NumberPattern::padded()` then
     * refuses to reconstitute, i.e. a row that cannot be read back at all.
     */
    public function testTheRenderedColumnIsExactlyAsWideAsTheWidestPermittedPattern(): void
    {
        $statement = self::owner()->query(
            "SELECT character_maximum_length FROM information_schema.columns "
            . "WHERE table_name = 'document' AND column_name = 'number_rendered'",
        );
        self::assertInstanceOf(\PDOStatement::class, $statement);

        self::assertSame(
            20,
            (int) $statement->fetchColumn(),
            'VARCHAR(20), matching NumberPattern::MAX_WIDTH — see the migration for why the bound is exact',
        );

        // And the widest permitted pattern really does fit, asserted by inserting it rather than by arithmetic.
        self::insertDocument(41, str_pad('41', 20, '0', \STR_PAD_LEFT));


        // One character more must be refused by the column itself, which is the other direction of the same bound.
        $this->expectException(\PDOException::class);
        self::insertDocument(41, str_pad('41', 21, '0', \STR_PAD_LEFT));
    }

    /**
     * Insert one document row, in ITS OWN TENANT.
     *
     * **A FRESH TENANT PER ROW, and that is a fix rather than tidiness.** The first version of this helper generated
     * a fresh `id` per row with a comment explaining that a fixed id would collide on the primary key and "would
     * look exactly like the constraint under test refusing it" — then shared one `company_id` across every case, so
     * two cases using sequence 41 collided on `document_number_unique_per_tenant_and_type` instead. [Verified: the
     * width test failed with `duplicate key value violates unique constraint
     * "document_number_unique_per_tenant_and_type"`.] So the hazard was correctly identified and then reintroduced
     * one column across, which is the full-set-coverage rule in `CLAUDE.md`: uniqueness here is
     * `(company_id, type, number)` and a fixture must vary enough of it that no case can be refused by the wrong
     * constraint.
     *
     * Varying the TENANT rather than the sequence is what keeps the `(number, number_rendered)` pairs in the data
     * providers readable and correct — the pair has to agree, so a helper that rewrote the sequence would have to
     * rewrite the rendering too, and then the fixture would be computing the thing under test.
     *
     * @return array{string, string} the tenant and document ids
     */
    private static function insertDocument(?int $number, ?string $rendered): array
    {
        $company = self::freshUuid();
        $id = self::freshUuid();

        // Rebound per insert, because the row must satisfy the tenant policy's WITH CHECK half and the tenant is
        // different every time. Session-scoped for the reason given on `owner()`.
        self::owner()->exec(\sprintf("SET twes.tenant_id = '%s'", $company));

        $statement = self::owner()->prepare(
            'INSERT INTO document (company_id, id, type, state, currency, number, number_rendered, '
            . 'vat_rounding_point) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $company,
            $id,
            'invoice',
            null === $number ? 'draft' : 'issued',
            'TND',
            $number,
            $rendered,
            'per_rate_group',
        ]);

        return [$company, $id];
    }

    /** A syntactically valid v4-shaped UUID. Not `UuidV7Generator`: this is a fixture, and CLAUDE.md § Gotchas rules that a v7 identifier is an ordering artefact, never a source of uniqueness guarantees. */
    private static function freshUuid(): string
    {
        return \sprintf(
            '%08x-%04x-4%03x-8%03x-%012x',
            random_int(0, 0xFFFFFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0xFFF),
            random_int(0, 0xFFF),
            random_int(0, 0xFFFFFFFFFFFF),
        );
    }

    /**
     * The OWNER connection, not the runtime role, and that is not laziness.
     *
     * `document` has `FORCE ROW LEVEL SECURITY`, so even the owner is policed — but the owner is not bound to a
     * tenant here, and the canonical policy compares against `current_setting('twes.tenant_id', true)`, which is
     * NULL when unset and therefore admits nothing. So these inserts bind a tenant explicitly per connection. The
     * subject of this test is the CHECK constraints, which are evaluated before any policy and are identical for
     * every role; tenant isolation is `BehaviouralIsolationTest`'s subject and is deliberately not re-proven here.
     */
    private static function owner(): \PDO
    {
        if (null === self::$owner) {
            self::$owner = self::connectionTo(
                self::DATABASE,
                self::ownerRole(),
                self::ownerPassword(),
            );
            // NO INITIAL BINDING HERE: every insert binds its own tenant, so a binding set once at connection
            // time would be overwritten before it was ever used — a vestigial fixture value that reads as though
            // the cases share a tenant when they deliberately do not.
        }

        return self::$owner;
    }
}
