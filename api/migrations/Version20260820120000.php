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
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Settings\CompanySettings;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;

/**
 * `company_settings` — the per-tenant document configuration Wave 1 owed, and the end of two hardwires.
 *
 * `build-waves.plan.md` § Wave 1 names this table: *"the per-tenant `NumberPattern`, plus the per-company
 * `vat_rounding_point` DEFAULT — both named as persisted configuration and neither specified … They belong on
 * the company/tenant settings table that Wave 1 must create anyway."* Until now `config/services.yaml` wired
 * `$numberWidth: 7` and `CreateInvoiceProcessor` passed `VatRoundingPoint::PerRateGroup` as a literal, which
 * made `PerLine` **unreachable over HTTP** — half the calculation kernel could not be exercised by any caller.
 *
 * **HAND-WRITTEN, not `doctrine:migrations:diff`**, for the reason `Version20260801120000` gives: a diff
 * produces the `CREATE TABLE` and none of the row-level-security statements a tenant-owned table needs, and
 * those are the half that matters. The policy SQL comes from `policySqlFor()` rather than being spelled out
 * here, so the migration and `scripts/gates/schema-tenancy.php` cannot disagree about what a canonical policy
 * is.
 *
 * **PRIMARY KEY IS `(company_id)` ALONE, AND THAT IS A DEPARTURE WORTH DEFENDING.** Every other tenant-owned
 * table in this schema carries `PRIMARY KEY (company_id, id)`, and § Wave 1 states that as a rule. The reason
 * for the rule is that uniqueness checks run with row security BYPASSED, so a key omitting the tenant is
 * enforced across every tenant — an existence oracle. That reason is *satisfied* here rather than waived: the
 * tenant column IS the whole key, so no cross-tenant comparison is possible, and `schema-tenancy.php`'s key
 * axis asks that every key INCLUDE the tenant column, which `(company_id)` does. What the departure buys is
 * the invariant itself — **exactly one settings row per tenant** — which a surrogate `id` would destroy: with
 * `(company_id, id)` a tenant could hold two settings rows, and every read would have to pick one. This is
 * the same argument `document_number_sequence` makes for `PRIMARY KEY (company_id, type)`, where the key is
 * load-bearing for the mechanism and not only for the data.
 *
 * **NO SURROGATE `id`, AND NO `created_at`/`updated_at`.** Neither is needed by anything: the row is found by
 * its tenant, and nothing yet audits when a setting changed. Adding columns a migration cannot justify is how
 * a schema acquires fields no code reads — and a settings-history requirement, if it ever arrives, is a
 * separate table rather than two timestamps on this one.
 *
 * **NO BACKFILL, AND NO ROW IS CREATED FOR ANY EXISTING TENANT.** There is no tenant registry to enumerate —
 * a tenant exists the moment a request carries its id, and tenant provisioning arrives with Wave 7's
 * authentication. So a tenant with no row is the ORDINARY case on the day this lands, not an edge one, and
 * {@see \Twes\Domain\Settings\CompanySettingsRepository::forCurrentTenant()} answers it with
 * {@see CompanySettings::defaults()} — values that reproduce the two retired wires EXACTLY, which is what
 * makes this migration behaviour-preserving for every tenant that has configured nothing.
 *
 * **THE COLUMN `DEFAULT`S ARE DERIVED FROM THE DOMAIN, NOT TYPED OUT.** A default written here as a literal
 * would be a third copy of a value that also lives in `CompanySettings` and in the adapter, and
 * `CLAUDE.md` § Gotchas records what happens to duplicated conditions. The same applies to the CHECK on the
 * rounding point: its permitted set comes from `VatRoundingPoint::cases()`, so adding a case to the enum
 * without a migration is a schema failure rather than a silently-rejected value. Note the `DEFAULT`s are
 * genuinely belt-and-braces — the adapter always writes both columns explicitly — but they are what makes a
 * hand-written `INSERT` in a psql session produce a valid row rather than an error.
 */
final class Version20260820120000 extends AbstractMigration
{
    private const string TABLE = 'company_settings';

    public function getDescription(): string
    {
        return 'Per-tenant document settings: number-pattern width and the default VAT rounding point.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration issues PostgreSQL row-level-security statements. twes-in targets PostgreSQL only '
            . '— see CLAUDE.md § Architecture — so running it elsewhere would create the table WITHOUT the '
            . 'isolation that makes it safe to put tenant data in, which is worse than not creating it.',
        );

        $this->addSql(
            \sprintf(
                'CREATE TABLE %s ('
                . '%s uuid NOT NULL, '
                . 'number_pattern_width smallint NOT NULL DEFAULT %d, '
                . 'default_vat_rounding_point varchar(%d) NOT NULL DEFAULT %s, '
                . 'PRIMARY KEY (%s)'
                . ')',
                self::TABLE,
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
                CompanySettings::DEFAULT_NUMBER_PATTERN_WIDTH,
                self::roundingPointColumnLength(),
                $this->quoteLiteral(CompanySettings::defaultVatRoundingPointBackedValue()),
                PostgresRowLevelSecurityIsolation::TENANT_COLUMN,
            ),
        );

        // THE WIDTH IS BOUNDED AT BOTH ENDS, matching `NumberPattern::padded()`'s own refusals exactly. Round 16
        // found this table's specification claiming the column "matched padded()'s own refusal" when only the
        // LOWER bound did — the domain accepted any width up to PHP_INT_MAX while the column was a smallint. That
        // is the domain-admits-what-persistence-rejects mismatch, and deriving both bounds from the same constant
        // is what stops it returning: widening MAX_WIDTH without a migration now fails here rather than at an
        // INSERT nobody runs until production.
        $this->addSql(
            \sprintf(
                'ALTER TABLE %s ADD CONSTRAINT company_settings_number_pattern_width_is_renderable '
                . 'CHECK (number_pattern_width BETWEEN 1 AND %d)',
                self::TABLE,
                NumberPattern::MAX_WIDTH,
            ),
        );

        $this->addSql(
            \sprintf(
                'ALTER TABLE %s ADD CONSTRAINT company_settings_rounding_point_is_known '
                . 'CHECK (default_vat_rounding_point IN (%s))',
                self::TABLE,
                implode(', ', array_map(
                    fn(VatRoundingPoint $point): string => $this->quoteLiteral($point->value),
                    VatRoundingPoint::cases(),
                )),
            ),
        );

        // ROW-LEVEL SECURITY. This table holds one row per tenant and nothing else, so it is as tenant-owned as a
        // table gets — and it is exactly the shape `schema-tenancy.php` would refuse to classify if the column
        // were named anything but TENANT_COLUMN. Enable, FORCE, and the canonical policy on both halves, all from
        // policySqlFor() so this migration cannot invent a variant the gate would then have to accept.
        foreach (PostgresRowLevelSecurityIsolation::policySqlFor(self::TABLE) as $statement) {
            $this->addSql($statement);
        }
    }

    public function down(Schema $schema): void
    {
        // No children and no foreign keys, so nothing to order. Dropping the table drops its policy with it.
        $this->addSql(\sprintf('DROP TABLE IF EXISTS %s', self::TABLE));
    }

    /**
     * How wide the rounding-point column must be to hold any case the enum can produce.
     *
     * DERIVED rather than a round number, for the reason the width CHECK above is derived: a case added later
     * with a longer backed value would be silently truncated by a hand-picked `varchar(32)`, and a truncated
     * enum value is a row that fails its own CHECK for a reason nobody can read. The CHECK constraint is the
     * real guard on the CONTENT; this length only has to be big enough not to fight it.
     */
    private static function roundingPointColumnLength(): int
    {
        $longest = 0;

        foreach (VatRoundingPoint::cases() as $point) {
            $longest = max($longest, \strlen($point->value));
        }

        return $longest;
    }

    /**
     * A single-quoted SQL literal, with embedded quotes doubled.
     *
     * Written out rather than reaching for `$this->connection->quote()` because every value passed here is a
     * PHP enum case's backed value or a class constant — compile-time text, never input — and a migration that
     * built its DDL from the connection would be harder to read for no gain in safety. The doubling is still
     * done rather than assumed, because assuming a value contains no quote is how the assumption stops holding.
     */
    private function quoteLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
