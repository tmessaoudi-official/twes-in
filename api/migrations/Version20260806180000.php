<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `document.number_rendered` — the number AS IT WAS RENDERED WHEN ISSUED, so no later setting can restate it.
 *
 * **The ruling (2026-08-01) and why it is unfixable later.** A document number is *(type, pattern, sequence)*, and
 * the pattern is per-tenant configuration. `Version20260801120000` stored the sequence alone, so reconstituting a
 * number meant re-rendering it through whatever width was current — and an administrator widening the pattern from 7
 * to 8 turned an already-issued `0000041` into `00000041`. That is a **different number on a legal document a client
 * already holds**, against a product that promises byte-identical re-download. It belongs in the same class as the
 * gapless sequence and money-is-never-a-float: cheap now, impossible once real invoices exist.
 *
 * The sequence STAYS the identity — `(company_id, type, number)` uniqueness, every ORDER BY and the gapless audit
 * trail are built on it — and the string is stored beside it. One column. Rejected by name in the ruling:
 * snapshotting the pattern width per document (equivalent guarantee, more indirection) and treating rendering as
 * presentational (the cheaper reading, and the one that cannot be undone).
 *
 * **THE BACKFILL IS A NO-OP IN EVERY DATABASE THAT EXISTS, AND THAT IS MEASURED RATHER THAN ASSUMED.**
 * [Verified 2026-08-06: `SELECT count(*) FROM document` → 0 and `… WHERE number IS NOT NULL` → 0 in the local dev
 * database, the only one that has ever run the parent migration.] So the "a document issued before the column lands
 * and read after it may render differently, once" hazard that `InvoiceMapper` documented never materialised — no
 * document has ever been read back through the old default. The `UPDATE` below is still written, because a migration
 * must be correct on data it did not expect to find, and it uses width 7 because that was the default the read path
 * actually used: reproducing the previous behaviour exactly is the only backfill that cannot change a rendering.
 *
 * **NOT `NOT NULL`, and the reason is the pairing.** A draft has no number, so the column must be nullable — but the
 * two halves must be absent or present TOGETHER, which is a relationship between columns rather than a property of
 * one. `NOT NULL` cannot express it and neither can a constructor parameter, which is why `DocumentRow`'s
 * forget-a-column guard explicitly excludes this pair. `document_number_halves_are_paired` is the enforcement, and
 * `InvoiceMapper::numberFrom()` refuses both halves of the mismatch again in PHP — because the constraint protects
 * the database from a direct `UPDATE` while the mapper gives a caller a message it can act on.
 *
 * **NO ROW-LEVEL SECURITY WORK HERE, deliberately.** `document` is already RLS-enabled, `FORCE`d and policed by the
 * parent migration, and a policy is per TABLE rather than per column — so a new column inherits the existing policy
 * with nothing to add. `scripts/gates/schema-tenancy.php` is what would say otherwise: it reports on tables, not
 * columns, and it stays green because no table changed. Stating this is the point rather than padding, since a
 * migration touching a tenant-owned table and issuing no isolation statements is exactly the shape that gate exists
 * to catch, and a reader is owed the reason it is correct here.
 */
final class Version20260806180000 extends AbstractMigration
{
    /**
     * The width the read path used before this column existed, and the ONLY defensible backfill value.
     *
     * It is not a preference: `InvoiceMapper` re-rendered through `NumberPattern::padded(7)`, so 7 is what any
     * already-issued document has been rendering as. A different value here would change a rendering during the
     * migration, which is the exact defect the column exists to prevent.
     */
    private const int PREVIOUS_DEFAULT_WIDTH = 7;

    public function getDescription(): string
    {
        return 'Persist the rendered document number beside its sequence, so configuration cannot restate an issued document.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration uses a PostgreSQL regular-expression CHECK and lpad(). twes-in targets PostgreSQL only '
            . '— see CLAUDE.md § Architecture — so running it elsewhere would add the column without either of the '
            . 'two constraints that make it trustworthy.',
        );

        // VARCHAR(20), matching NumberPattern::MAX_WIDTH. That bound is exact rather than generous: format() grows
        // past its padding only as far as the digits of the largest sequence the domain admits, and PHP_INT_MAX is
        // 19 digits. [Verified: strlen((string) PHP_INT_MAX) → 19, MAX_WIDTH → 20.] A wider column would admit a
        // string NumberPattern::padded() then refuses to reconstitute, which is a row that cannot be read back.
        $this->addSql('ALTER TABLE document ADD number_rendered VARCHAR(20) DEFAULT NULL');

        // BEFORE the constraints, or the paired-nullability CHECK would reject every already-issued row as it is
        // added. A no-op on every existing database (see the class docblock) and correct if one is ever not empty.
        $this->addSql(\sprintf(
            'UPDATE document SET number_rendered = lpad(number::text, %d, \'0\') WHERE number IS NOT NULL',
            self::PREVIOUS_DEFAULT_WIDTH,
        ));

        // THE PAIRING, and it is written as an equality between two IS NULL tests rather than as two implications.
        // `(a IS NULL) = (b IS NULL)` is one expression that cannot be half-written, and in PostgreSQL both sides
        // are strictly boolean, so it never evaluates to NULL and never admits a row the way a three-valued
        // comparison of the columns themselves would.
        $this->addSql(
            'ALTER TABLE document ADD CONSTRAINT document_number_halves_are_paired '
            . 'CHECK ((number IS NULL) = (number_rendered IS NULL))',
        );

        // DIGITS ONLY, and `+` rather than `*` so the empty string is refused too — an empty rendered number is a
        // document that prints nothing where its number belongs, and it would satisfy a `*` pattern.
        //
        // This is the constraint that makes a future change to what a number LOOKS LIKE a visible decision. The day
        // a tenant wants `INV-2026-0041`, this fails and somebody has to decide what the stored string means for
        // ordering, search and the uniqueness index — instead of a string quietly ceasing to be any of those.
        $this->addSql(
            'ALTER TABLE document ADD CONSTRAINT document_number_rendered_is_digits '
            . "CHECK (number_rendered IS NULL OR number_rendered ~ '^[0-9]+$')",
        );
    }

    public function down(Schema $schema): void
    {
        // Constraints first: dropping the column would take them with it, but naming them makes the inverse
        // explicit and survives a future change that keeps the column while replacing a constraint.
        //
        // `IF EXISTS` ON ALL THREE, AND IT IS NOT AN UNEXAMINED SUPPRESSION. The anti-bandaid rule asks for the
        // failure mode and the evidence: Doctrine Migrations executes each `addSql` in sequence, so a failure part
        // way through `up()` leaves a REAL partial state — the column added and the second constraint not, say — and
        // `down()` is the documented way back from it. Without `IF EXISTS` the recovery path itself fails on the
        // first object that was never created, which is the worst moment for a migration to become unrunnable. This
        // is also the parent migration's own convention (`DROP TABLE IF EXISTS`), so it is consistency rather than a
        // new tolerance. It is NOT used anywhere in `up()`, where an unexpected pre-existing object must fail loudly.
        $this->addSql('ALTER TABLE document DROP CONSTRAINT IF EXISTS document_number_rendered_is_digits');
        $this->addSql('ALTER TABLE document DROP CONSTRAINT IF EXISTS document_number_halves_are_paired');
        $this->addSql('ALTER TABLE document DROP COLUMN IF EXISTS number_rendered');
    }
}
