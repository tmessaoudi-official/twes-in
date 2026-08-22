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
use Twes\Domain\Document\DocumentState;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;

/**
 * `document.client_id` — the last thing Wave 1's `In:` line owes.
 *
 * An invoice could not say who it was addressed to. EN 16931 makes the buyer mandatory (BT-44), so an invoice
 * with no client is not a document a tax authority accepts — and a `client` table nothing points at is half a
 * feature.
 *
 * ## NULLABLE, with the requirement attached to the TRANSITION
 *
 * **Ruled 2026-08-22, and argued rather than inherited: the plans were SILENT on whether an invoice requires a
 * client and at which point.** A foreign key's default nullability is not an argument, so the decision is
 * recorded in `build-waves.plan.md`'s Decisions Log in the same change that implements it.
 *
 * A DRAFT may have none, for exactly the reason it may have no LINES: deciding who an invoice is for can
 * legitimately come after typing what is on it, and forcing the choice at creation would mean a user cannot
 * start an invoice before making it. `document_client_required_once_issued` is the schema half of
 * `Invoice::issue()`'s guard, which makes the two statements of one rule — and it is written against
 * `DocumentState::Draft->value` rather than the literal `'draft'`, so renaming the case moves both.
 *
 * ## The foreign key RESTRICTS rather than cascading, and that is the whole point of it
 *
 * `client_contact` cascades from `client` because a contact is PART of a client. A document is not: deleting a
 * client must never delete the invoices addressed to them, which would erase legal documents a tax authority can
 * ask for years later. `RESTRICT` turns "a client with invoices cannot be deleted" into a fact of the database
 * rather than a rule somebody has to remember — and it is exactly the question `ClientResource` defers by having
 * no `DELETE`. When that endpoint is designed, this constraint is what it has to answer to.
 *
 * **COMPOSITE, `(company_id, client_id)`,** for the reason every key here is: foreign-key checks run with row
 * security BYPASSED, so a key omitting the tenant would let one tenant's invoice reference another tenant's
 * client — a cross-tenant existence oracle, and with a cascade it would have been a cross-tenant delete.
 * `schema-tenancy.php` asserts that axis, and reads `confkey` in ORDINAL order precisely because a tenant column
 * present on both sides can still be MIS-PAIRED.
 */
final class Version20260822220000 extends AbstractMigration
{
    private const string DOCUMENT = 'document';

    public function getDescription(): string
    {
        return 'A document may name a client; an issued one must.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(\sprintf('ALTER TABLE %s ADD COLUMN client_id UUID DEFAULT NULL', self::DOCUMENT));

        // RESTRICT on BOTH halves. `ON DELETE` is the one that matters here; `ON UPDATE` is stated rather than
        // left to the default because a client id is never updated in this system, so a rule permitting it
        // would describe a capability nobody wants.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT document_is_addressed_to_a_client '
            . 'FOREIGN KEY (%s, client_id) REFERENCES client (%s, id) ON DELETE RESTRICT ON UPDATE RESTRICT',
            self::DOCUMENT,
            PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
            PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
        ));

        // THE SCHEMA HALF OF `Invoice::issue()`'s GUARD. Derived from the enum rather than written as `'draft'`,
        // so renaming the case moves the constraint with it — the same reason every other bound in these
        // migrations is derived from the domain.
        //
        // **`NOT VALID`, and that is the honest answer to a rule introduced after data exists rather than a way
        // to make the migration pass.** This constraint was written and immediately refused by a real database:
        // documents issued before today carry no client, because there was no column to put one in. The three
        // options were backfill, grandfather, or destroy.
        //
        //   - BACKFILL is impossible. There is no correct client to invent for an invoice that never named one,
        //     and guessing would write a false statement onto a legal document.
        //   - DESTROYING the rows is unthinkable for issued invoices: a tax authority can ask for them years
        //     later, which is the whole reason a cancelled document keeps its number on file.
        //   - GRANDFATHERING is what `NOT VALID` means, precisely: PostgreSQL skips the scan of existing rows
        //     and ENFORCES THE CHECK ON EVERY INSERT AND UPDATE FROM NOW ON. New documents obey the rule; the
        //     ones that predate it are left as the historical record they are.
        //
        // A later migration may `VALIDATE CONSTRAINT` once somebody decides what those rows should say — that is
        // a data decision rather than a schema one, and it is deliberately not being made here in passing.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT document_client_required_once_issued '
            . "CHECK (state = '%s' OR client_id IS NOT NULL) NOT VALID",
            self::DOCUMENT,
            DocumentState::Draft->value,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(\sprintf(
            'ALTER TABLE %s DROP CONSTRAINT IF EXISTS document_client_required_once_issued',
            self::DOCUMENT,
        ));
        $this->addSql(\sprintf(
            'ALTER TABLE %s DROP CONSTRAINT IF EXISTS document_is_addressed_to_a_client',
            self::DOCUMENT,
        ));
        $this->addSql(\sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS client_id', self::DOCUMENT));
    }
}
