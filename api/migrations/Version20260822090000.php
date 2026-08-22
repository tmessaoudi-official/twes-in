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
use Twes\Domain\Client\Client;
use Twes\Domain\Client\Contact;
use Twes\Domain\Client\PostalAddress;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;

/**
 * `client` and `client_contact` — the party an invoice is addressed to, and the people at it.
 *
 * `build-waves.plan.md` § Wave 1's `In:` line names **Client (+ contacts)**, and the MAXIMAL panel retracted
 * the wave-complete claim on exactly that ground: neither existed in any form. This is the schema half.
 *
 * **HAND-WRITTEN, not `doctrine:migrations:diff`**, for the reason `Version20260801120000` gives and
 * `Version20260820120000` repeats: a diff produces the `CREATE TABLE` and none of the row-level-security
 * statements a tenant-owned table needs, and those are the half that matters. The policy SQL comes from
 * `policySqlFor()` rather than being spelled out here, so this migration and `scripts/gates/schema-tenancy.php`
 * cannot disagree about what a canonical policy is.
 *
 * **EVERY KEY INCLUDES THE TENANT COLUMN**, which is not stylistic: uniqueness, exclusion and foreign-key
 * checks run with row security **BYPASSED**, so a key omitting the tenant is enforced across every tenant at
 * once. For a unique key that is an existence oracle over every tenant's client list; for a foreign key with
 * `ON DELETE CASCADE` it is a cross-tenant delete. `schema-tenancy.php` asserts this axis, and its docblock
 * records it being DELETED once on the claim that the behavioural suite covered it, and two lenses
 * independently reproducing an oracle without it.
 *
 * **THE BOUNDS AND THE COUNTRY-CODE SHAPE ARE DERIVED FROM THE DOMAIN, NOT TYPED OUT.** A literal here would
 * be a second copy of a number that also lives on {@see Client}, {@see Contact} and {@see PostalAddress}, and
 * `CLAUDE.md` § Gotchas records what happens to a duplicated condition. Deriving them means widening a domain
 * bound without a migration fails HERE, loudly, rather than at an `INSERT` nobody runs until production —
 * which is the `MAX_WIDTH` mismatch round 16 found on `company_settings`, avoided by construction.
 *
 * **WHAT IS DELIBERATELY *NOT* CONSTRAINED, because a CHECK is the wrong place for it:**
 * - **The e-mail format.** PostgreSQL's regex and PHP's `filter_var($x, FILTER_VALIDATE_EMAIL)` do not agree
 *   on the edges, so a CHECK would be a SECOND definition of "valid address" that drifts from the domain's —
 *   and the one that rejects would do so with a constraint name instead of a message. {@see Contact} owns
 *   that rule.
 * - **The tax-identifier format.** There are as many formats as tax authorities; {@see Client} argues at
 *   length that refusing an unfamiliar one makes a legitimate client unrepresentable.
 * - **The telephone format**, for the same reason.
 * Each of those is bounded in LENGTH here, because a length is a storage fact rather than a business rule.
 */
final class Version20260822090000 extends AbstractMigration
{
    private const string CLIENT = 'client';
    private const string CONTACT = 'client_contact';

    public function getDescription(): string
    {
        return 'Clients and their contacts: EN 16931 BG-7 buyer, BG-8 postal address and BG-9 contact.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration issues PostgreSQL row-level-security statements. twes-in targets PostgreSQL only '
            . '— see CLAUDE.md § Architecture — so running it elsewhere would create the tables WITHOUT the '
            . 'isolation that makes it safe to put tenant data in, which is worse than not creating them.',
        );

        // THE ADDRESS IS FIVE NULLABLE COLUMNS RATHER THAN A JSON BLOB OR A SEPARATE TABLE. A blob cannot be
        // indexed, constrained or read by an e-invoicing exporter without parsing; a separate table would be a
        // one-to-at-most-one join for a value object that has no identity of its own. The all-or-nothing rule
        // PostalAddress enforces in PHP is enforced here as a CHECK, so the two cannot disagree about what a
        // half address is.
        $this->addSql(
            \sprintf(
                'CREATE TABLE %s ('
                . '%s UUID NOT NULL, '
                . 'id UUID NOT NULL, '
                . 'name TEXT NOT NULL, '
                . 'tax_identifier VARCHAR(%d) DEFAULT NULL, '
                . 'address_line_1 TEXT DEFAULT NULL, '
                . 'address_line_2 TEXT DEFAULT NULL, '
                . 'address_postcode TEXT DEFAULT NULL, '
                . 'address_city TEXT DEFAULT NULL, '
                . 'address_country_code CHAR(2) DEFAULT NULL, '
                . 'PRIMARY KEY (%s, id))',
                self::CLIENT,
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
                Client::MAX_TAX_IDENTIFIER_LENGTH,
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
            ),
        );

        // A CONTACT HAS BOTH AN ID AND A POSITION, and both are load-bearing for different reasons. The id is
        // its IDENTITY -- Wave 4 e-mails a PDF to a contact and Wave 10 invites one to the portal, so a stored
        // reference has to survive its neighbours being deleted, which is exactly what a positional model cannot
        // promise. The position is its ORDER, which is what a user arranged and which a list that reshuffles
        // itself between two page loads would lose. `document_line` needs only the second because nothing ever
        // refers to a line.
        //
        // PRIMARY KEY (company_id, client_id, id) is the schema-level statement of the domain invariant that
        // contact ids are unique WITHIN a client -- see Client's constructor, which refuses a duplicate on every
        // path in including rehydration.
        $this->addSql(
            \sprintf(
                'CREATE TABLE %s ('
                . '%s UUID NOT NULL, '
                . 'client_id UUID NOT NULL, '
                . 'id UUID NOT NULL, '
                . 'position SMALLINT NOT NULL, '
                . 'name TEXT NOT NULL, '
                . 'email TEXT DEFAULT NULL, '
                . 'phone VARCHAR(%d) DEFAULT NULL, '
                . 'PRIMARY KEY (%s, client_id, id))',
                self::CONTACT,
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
                Contact::MAX_PHONE_LENGTH,
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
            ),
        );

        // ONE POSITION PER CONTACT WITHIN A CLIENT. Two contacts sharing a position makes the stored ORDER
        // ambiguous, and an aggregate that reads back in a different order than it was written is the
        // byte-identical-re-download problem in miniature. The tenant column leads the key for the reason this
        // class's docblock gives: a unique key omitting it is enforced across every tenant.
        $this->addSql(
            \sprintf(
                'CREATE UNIQUE INDEX client_contact_position_unique_per_client ON %s (%s, client_id, position)',
                self::CONTACT,
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
            ),
        );

        // COMPOSITE foreign key, exactly as `document_line` has. A single-column FK on client_id alone would let
        // one tenant reference -- and therefore cascade-delete -- another tenant's client. ON DELETE CASCADE is
        // safe precisely BECAUSE it is composite, and it is what makes deleting a client take its contacts with
        // it rather than leaving orphans the aggregate could never load.
        $this->addSql(
            \sprintf(
                'ALTER TABLE %s ADD CONSTRAINT client_contact_belongs_to_client '
                . 'FOREIGN KEY (%s, client_id) REFERENCES %s (%s, id) ON DELETE CASCADE',
                self::CONTACT,
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
                self::CLIENT,
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
            ),
        );

        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT client_name_is_present CHECK (btrim(name) <> %s)',
            self::CLIENT,
            $this->quoteLiteral(''),
        ));
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT client_name_is_bounded CHECK (char_length(name) <= %d)',
            self::CLIENT,
            Client::MAX_NAME_LENGTH,
        ));

        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT client_contact_name_is_present CHECK (btrim(name) <> %s)',
            self::CONTACT,
            $this->quoteLiteral(''),
        ));
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT client_contact_name_is_bounded CHECK (char_length(name) <= %d)',
            self::CONTACT,
            Contact::MAX_NAME_LENGTH,
        ));

        // THE ALL-OR-NOTHING ADDRESS RULE, as the database states it. Either every part is absent, or the three
        // REQUIRED parts are present -- line 2 and the postcode stay independent, because PostalAddress makes
        // them optional (Ireland had no general postcode system until 2015). The `line_2 IS NULL` and
        // `postcode IS NULL` conjuncts on the absent branch are what stop a postcode floating with no address
        // to belong to.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT client_address_is_whole CHECK ('
            . '(address_line_1 IS NULL AND address_line_2 IS NULL AND address_postcode IS NULL '
            . 'AND address_city IS NULL AND address_country_code IS NULL) '
            . 'OR (address_line_1 IS NOT NULL AND address_city IS NOT NULL AND address_country_code IS NOT NULL)'
            . ')',
            self::CLIENT,
        ));

        // The country-code SHAPE, matching PostalAddress's own pattern exactly. `CHAR(2)` already fixes the
        // length, so what this adds is the alphabet and the case -- and the case matters, because the domain
        // REFUSES lowercase rather than normalising it, so a row inserted by hand in psql must obey the same
        // rule or it would read back as a value the domain cannot construct.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT client_country_code_is_alpha_2 CHECK ('
            . 'address_country_code IS NULL OR address_country_code ~ %s)',
            self::CLIENT,
            $this->quoteLiteral('^[A-Z]{2}$'),
        ));

        // TWO SEPARATE STATEMENTS RATHER THAN ONE LOOP OVER BOTH TABLES. The first draft was a loop with a
        // ternary inside it, which named the contact table's constraint `client_contact_address_parts_are_bounded`
        // while what it actually bounded was the e-mail — a constraint whose NAME is what a developer reads out
        // of a violation message, so a misleading one sends them looking at the wrong column. Two statements say
        // what each is.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT client_address_parts_are_bounded CHECK ('
            . '(address_line_1 IS NULL OR char_length(address_line_1) <= %2$d) '
            . 'AND (address_line_2 IS NULL OR char_length(address_line_2) <= %2$d) '
            . 'AND (address_postcode IS NULL OR char_length(address_postcode) <= %2$d) '
            . 'AND (address_city IS NULL OR char_length(address_city) <= %2$d))',
            self::CLIENT,
            PostalAddress::MAX_PART_LENGTH,
        ));

        // The e-mail is bounded by the same constant the domain bounds it with — `Contact` reuses its name bound
        // there rather than inventing a second number, and this follows it for the same reason.
        $this->addSql(\sprintf(
            'ALTER TABLE %s ADD CONSTRAINT client_contact_email_is_bounded CHECK ('
            . 'email IS NULL OR char_length(email) <= %d)',
            self::CONTACT,
            Contact::MAX_NAME_LENGTH,
        ));

        // ROW-LEVEL SECURITY on BOTH tables. A client list is commercially sensitive on its own -- who a
        // competitor's customers are -- and `client_contact` holds personal data, which makes a leak here a
        // reportable one rather than merely embarrassing. Enable, FORCE and the canonical policy on both halves,
        // all from policySqlFor() so this migration cannot invent a variant the gate would then have to accept.
        foreach ([self::CLIENT, self::CONTACT] as $table) {
            foreach (PostgresRowLevelSecurityIsolation::policySqlFor($table) as $statement) {
                $this->addSql($statement);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // CHILD FIRST. The composite FK means dropping `client` while `client_contact` references it would fail,
        // and an ordering that only works because of CASCADE is one that breaks the day somebody removes it.
        $this->addSql(\sprintf('DROP TABLE IF EXISTS %s', self::CONTACT));
        $this->addSql(\sprintf('DROP TABLE IF EXISTS %s', self::CLIENT));
    }

    /**
     * A single-quoted SQL literal, with embedded quotes doubled.
     *
     * Written out rather than reaching for `$this->connection->quote()` for the reason
     * `Version20260820120000` gives: every value passed here is a class constant or compile-time text, never
     * input, and a migration that built its DDL from the connection would be harder to read for no gain in
     * safety. The doubling is still done rather than assumed, because assuming a value contains no quote is how
     * the assumption stops holding.
     */
    private function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
