<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Settings\CompanySettings;
use Twes\Domain\Settings\CompanySettingsRepository;
use Twes\Infrastructure\Tenancy\TenantContext;

/**
 * The PostgreSQL adapter for {@see CompanySettingsRepository}.
 *
 * DBAL rather than the ORM, matching {@see DoctrineInvoiceRepository} — see `CLAUDE.md` § Gotchas 2026-08-06
 * for the measured reason that applies there. Here it is simpler: the whole table is two scalar columns keyed
 * by the tenant, so an identity map buys nothing, and the write is one `INSERT … ON CONFLICT DO UPDATE` that
 * the unit of work cannot express as a single statement anyway. The mapped
 * {@see Entity\CompanySettingsRow} still earns its place — it is what `doctrine:schema:validate` checks the
 * migration against, and what a future `migrations:diff` would compare to.
 *
 * **BOTH METHODS REFUSE OUTSIDE A TRANSACTION, AND FOR THE READ THAT IS THE POINT RATHER THAN SYMMETRY.**
 * The tenant binding this table's row-level-security policy compares against is written by
 * `TenantBindingMiddleware` on `beginTransaction()` and is TRANSACTION-LOCAL (`set_config(…, true)`). So
 * outside a transaction there is no binding, an unbound session sees NOTHING, and a read would return zero
 * rows for a tenant that has settings — which this class would then be unable to distinguish from a tenant
 * that has none, and would answer with defaults. That is the exact failure the port's docblock forbids: a
 * fail-closed tenancy refusal silently downgraded to a wrong answer, with every default-shaped fixture still
 * green. Requiring the transaction makes the binding STRUCTURALLY PRESENT instead of something to verify, in
 * the same way `findForMutation()` states a guarantee rather than naming a lock. It is also why
 * `InvoiceProvider` wraps a pure read in a transaction — that reads like ceremony and is not
 * (`CLAUDE.md` § Gotchas 2026-08-07).
 *
 * **THE QUERY IS FILTERED BY THE TENANT AS WELL AS POLICED BY IT, which is not redundant.** Row-level
 * security scopes the statement to whatever `twes.tenant_id` the CONNECTION is bound to; the explicit
 * `company_id = :tenant` scopes it to whatever tenant the APPLICATION believes it is serving. Those are two
 * different facts, and the case where they disagree — a connection bound to another tenant than
 * `TenantContext` holds — is precisely the one that must not silently return somebody else's configuration.
 * With both, a disagreement yields zero rows rather than the wrong row.
 */
final readonly class DoctrineCompanySettingsRepository implements CompanySettingsRepository
{
    private const string TABLE = 'company_settings';

    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {}

    public function forCurrentTenant(): CompanySettings
    {
        $tenant = $this->currentTenant('read company settings');
        $this->requireTransaction('read company settings');

        /** @var array{number_pattern_width: int|string, default_vat_rounding_point: string}|false $row */
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT number_pattern_width, default_vat_rounding_point FROM %s WHERE company_id = :tenant',
                self::TABLE,
            ),
            ['tenant' => $tenant],
        );

        if (false === $row) {
            // THE ORDINARY CASE TODAY, not an edge one: nothing creates a settings row, because there is no
            // tenant-provisioning path until Wave 7's authentication. The defaults reproduce the two hardwires
            // this table retired, EXACTLY, so an unconfigured tenant behaves as it did before the table existed.
            // Reaching this line already proves a tenant is bound and a transaction is open, which is what makes
            // "no row" mean "has chosen nothing" rather than "could not see".
            return CompanySettings::defaults();
        }

        return CompanySettings::of(
            $this->numberPatternFrom((int) $row['number_pattern_width']),
            $this->roundingPointFrom($row['default_vat_rounding_point']),
        );
    }

    public function save(CompanySettings $settings): void
    {
        $tenant = $this->currentTenant('write company settings');
        $this->requireTransaction('write company settings');

        // ONE STATEMENT, so "create it if absent, otherwise replace it" has no window between the two halves.
        // The same shape as the gapless counter's upsert and for a related reason: two concurrent writes must
        // not be able to produce two rows for one tenant. Here the primary key would refuse the second anyway —
        // the upsert turns that refusal into the intended outcome instead of an error the caller must handle.
        //
        // NO `WHERE` PREDICATE ON THE `DO UPDATE`, unlike the document upsert. That one guards a write-once
        // number on a legal document; settings are meant to be changed, and the only invariant is one row per
        // tenant, which the key already enforces.
        $this->connection->executeStatement(
            \sprintf(
                'INSERT INTO %s (company_id, number_pattern_width, default_vat_rounding_point) '
                . 'VALUES (:tenant, :width, :roundingPoint) '
                . 'ON CONFLICT (company_id) DO UPDATE SET '
                . 'number_pattern_width = EXCLUDED.number_pattern_width, '
                . 'default_vat_rounding_point = EXCLUDED.default_vat_rounding_point',
                self::TABLE,
            ),
            [
                'tenant' => $tenant,
                'width' => $settings->numberPattern()->width(),
                'roundingPoint' => $settings->defaultVatRoundingPoint()->value,
            ],
        );
    }

    /**
     * The pattern for a stored width, refusing one `NumberPattern` does not accept.
     *
     * **A `\RuntimeException` AND NOT THE `\InvalidArgumentException` `NumberPattern::padded()` RAISES**, which is
     * the whole reason this wrapper exists rather than a bare call. That class is the domain's "the caller can
     * retype this" signal, and `CreateInvoiceProcessor` turns it into a **422** — so a width that defeated the
     * column's `CHECK (BETWEEN 1 AND NumberPattern::MAX_WIDTH)` would be reported to a client as their own bad
     * request, in a message naming an internal column. It is neither: reaching here means the schema and
     * `NumberPattern` have diverged, which `CLAUDE.md` § "Translation keys" puts squarely in `error.internal`.
     *
     * Same reasoning as {@see self::roundingPointFrom()}, and the two are deliberately symmetrical — the constraint
     * that should make each unreachable is a `CHECK` on the same table, so if one can be trusted to be unreachable
     * so can the other, and neither is.
     */
    private function numberPatternFrom(int $stored): NumberPattern
    {
        try {
            return NumberPattern::padded($stored);
        } catch (\InvalidArgumentException $refused) {
            throw new \RuntimeException(\sprintf(
                'The stored number pattern width %d is not one NumberPattern accepts. The %s.number_pattern_width '
                . 'column has a CHECK constraint matching exactly what that class allows, so reaching this means '
                . 'the schema and the domain have diverged — most likely MAX_WIDTH changed without a migration.',
                $stored,
                self::TABLE,
            ), 0, $refused);
        }
    }

    /**
     * The enum case for a stored backed value, refusing one the enum does not know.
     *
     * The column has a CHECK constraint listing exactly `VatRoundingPoint::cases()`, so this branch should be
     * unreachable through any supported path. It is still written, and it throws rather than falling back to a
     * default: a value that defeated the CHECK means the schema and the enum have diverged, and computing tax
     * with a guessed rounding point would put a number on a legal document that no configuration asked for.
     * `CLAUDE.md` § "Translation keys" puts this class of refusal in `error.internal` — it is our own layer
     * being wrong, not something a user can retype.
     */
    private function roundingPointFrom(string $stored): VatRoundingPoint
    {
        $point = VatRoundingPoint::tryFrom($stored);

        if (null === $point) {
            throw new \RuntimeException(\sprintf(
                'The stored default VAT rounding point "%s" is not a value this application knows. The '
                . '%s.default_vat_rounding_point column has a CHECK constraint listing exactly the cases of '
                . 'VatRoundingPoint, so reaching this means the schema and the enum have diverged — most likely '
                . 'a case was removed from the enum without a migration. Refusing rather than defaulting: the '
                . 'rounding point decides how much tax a document declares.',
                $stored,
                self::TABLE,
            ));
        }

        return $point;
    }

    /**
     * @throws \RuntimeException if no tenant is bound
     */
    private function currentTenant(string $attempted): string
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new \RuntimeException(\sprintf(
                'Refusing to %s with no tenant bound. Settings are per company, so a tenant-less read has no row '
                . 'to find — and under row-level security it would see nothing, which is indistinguishable from a '
                . 'company that has configured nothing. Answering that with defaults would turn a tenancy refusal '
                . 'into a silently wrong answer, which is the failure this refusal exists to prevent.',
                $attempted,
            ));
        }

        return $this->tenantContext->tenantId()->toString();
    }

    /**
     * @throws \RuntimeException if there is no active transaction
     */
    private function requireTransaction(string $attempted): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \RuntimeException(\sprintf(
                'Refusing to %s outside a transaction. The tenant binding this table is policed by is written on '
                . 'beginTransaction() and is transaction-local, so outside one the connection is bound to no '
                . 'tenant: a read would see zero rows for a company that has settings, and this adapter would '
                . 'report defaults it had no way to know were wrong. The transaction is what makes the binding '
                . 'present rather than assumed.',
                $attempted,
            ));
        }
    }
}
