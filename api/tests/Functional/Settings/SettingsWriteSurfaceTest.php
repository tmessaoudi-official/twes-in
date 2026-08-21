<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Functional\Settings;

use ApiPlatform\Metadata\Put;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Settings\CompanySettings;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\UI\Http\ApiResource\CompanySettingsInput;
use Twes\UI\Http\State\CompanySettingsProcessor;
use Twes\UI\Http\State\CompanySettingsProvider;

/**
 * `PUT /api/settings` — the only way a tenant can configure anything without a `psql` prompt.
 *
 * **WITHOUT THIS ENDPOINT THE SETTINGS TABLE IS THE HARDWIRE RELOCATED, and that is the whole reason it is
 * written.** `CompanySettingsRepository::save()` existed for a commit with no production call site at all: the
 * table was there, the adapter was there, the read path honoured it, and the only way to put a row in it was raw
 * SQL. `CLAUDE.md` § Gotchas records that exact shape four separate times — a guard on one write path, a meta-gate
 * reporting 33/33 for a gate that detected nothing, a permission constant no code consulted, and
 * `PostgresRowLevelSecurityIsolation::bind()` documented everywhere as the primary tenancy control while nothing
 * called it. A write method nothing calls is the same defect wearing a different hat.
 *
 * **IT ASSERTS THE PERSISTED COLUMNS, not the response body**, for the reason its sibling
 * {@see ConfiguredSettingsAreHonouredTest} gives at length: a representation handed back by a processor can agree
 * with the caller while the row disagrees with both.
 *
 * **A PUT AND NOT A PATCH.** The resource is two scalars that are always both present — there is no partial state
 * a client could legitimately hold — so a full replacement is the honest verb, and `save()` is already idempotent
 * because the tenant is the whole primary key. A PATCH would need a merge rule for absent fields, and "absent
 * means keep" is exactly the ambiguity that makes a rounding-point change hard to audit later.
 *
 * **NO POST AND NO DELETE.** A settings row is not created or destroyed by a client: every tenant has settings
 * from the moment it exists, expressed as {@see CompanySettings::defaults()} until it chooses otherwise. Offering
 * a DELETE would mean a client could ask for "no configuration", which is not a state this domain has.
 */
final class SettingsWriteSurfaceTest extends KernelTestCase
{
    private string $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->tenant = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->bindTenant();
    }

    /**
     * A tenant that has never configured anything reads the documented defaults rather than a 404.
     *
     * The absence of a row is not the absence of settings — see the class docblock on why there is no POST.
     */
    public function testATenantWithNoRowReadsTheDefaults(): void
    {
        $resource = self::getContainer()->get(CompanySettingsProvider::class)->provide(new Put());

        self::assertSame(CompanySettings::DEFAULT_NUMBER_PATTERN_WIDTH, $resource->numberPatternWidth);
        self::assertSame(CompanySettings::defaultVatRoundingPointBackedValue(), $resource->defaultVatRoundingPoint);
    }

    /**
     * THE WRITE, AND THE ROW IT PRODUCES.
     *
     * Values chosen not to be the defaults on BOTH fields: a case whose expected value equals what the code would
     * produce anyway cannot fail for the reason it was written.
     */
    public function testPuttingSettingsWritesThemForThisTenant(): void
    {
        $this->put(9, VatRoundingPoint::PerLine);

        self::assertSame(
            ['number_pattern_width' => 9, 'default_vat_rounding_point' => 'per_line'],
            $this->storedRow(),
            'PUT /api/settings must persist both fields for the bound tenant.',
        );
    }

    /**
     * PUTTING TWICE REPLACES, it does not create a second row — the upsert's whole point.
     *
     * Asserted through the surface rather than by reading `save()`, because "one row per tenant" is a property of
     * the primary key and this is the only path a client has to test it against.
     */
    public function testPuttingTwiceReplacesRatherThanDuplicating(): void
    {
        $this->put(9, VatRoundingPoint::PerLine);
        $this->put(4, VatRoundingPoint::PerRateGroup);

        self::assertSame(1, $this->storedRowCount(), 'a tenant has exactly one settings row, whatever it PUTs');
        self::assertSame(
            ['number_pattern_width' => 4, 'default_vat_rounding_point' => 'per_rate_group'],
            $this->storedRow(),
            'the second PUT replaces the first',
        );
    }

    /**
     * WHAT WAS WRITTEN IS WHAT IS READ BACK, through the provider rather than by trusting the processor.
     */
    public function testWhatWasPutIsWhatTheProviderThenReturns(): void
    {
        $this->put(9, VatRoundingPoint::PerLine);

        $resource = self::getContainer()->get(CompanySettingsProvider::class)->provide(new Put());

        self::assertSame(9, $resource->numberPatternWidth);
        self::assertSame('per_line', $resource->defaultVatRoundingPoint);
    }

    /**
     * A WIDTH THE DOMAIN REFUSES IS REFUSED HERE, and it never reaches the column's CHECK constraint.
     *
     * `NumberPattern::padded()` raises `\InvalidArgumentException`, which this layer turns into a 422 — the same
     * arrangement `CreateInvoiceProcessor` uses and for the same reason: the caller can retype it.
     */
    public function testAWidthTheDomainRefusesIsA422AndWritesNothing(): void
    {
        try {
            $this->put(0, VatRoundingPoint::PerLine);
            self::fail('a width of 0 must be refused');
        } catch (\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException) {
            // Expected.
        }

        self::assertSame(0, $this->storedRowCount(), 'a refused PUT writes nothing');
    }

    /**
     * A ROUNDING POINT THE ENUM DOES NOT KNOW IS A 422, not a 500 and not a stored value.
     */
    public function testAnUnknownRoundingPointIsA422(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException::class);

        self::getContainer()->get(CompanySettingsProcessor::class)
            ->process(new CompanySettingsInput(7, 'per_fortnight'), new Put());
    }

    private function put(int $width, VatRoundingPoint $roundingPoint): void
    {
        self::getContainer()->get(CompanySettingsProcessor::class)
            ->process(new CompanySettingsInput($width, $roundingPoint->value), new Put());
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private function storedRow(): ?array
    {
        return $this->inBoundTransaction(fn(Connection $connection): ?array => $connection->fetchAssociative(
            'SELECT number_pattern_width, default_vat_rounding_point FROM company_settings WHERE company_id = :t',
            ['t' => $this->tenant],
        ) ?: null);
    }

    private function storedRowCount(): int
    {
        return $this->inBoundTransaction(fn(Connection $connection): int => (int) $connection->fetchOne(
            'SELECT count(*) FROM company_settings WHERE company_id = :t',
            ['t' => $this->tenant],
        ));
    }

    /**
     * The binding row-level security compares against is transaction-local, so a read outside one sees nothing.
     *
     * @template T
     *
     * @param callable(Connection): T $read
     *
     * @return T
     */
    private function inBoundTransaction(callable $read): mixed
    {
        $this->bindTenant();
        $connection = self::getContainer()->get(Connection::class);

        return $connection->transactional(static fn(): mixed => $read($connection));
    }

    private function bindTenant(): void
    {
        self::getContainer()->get(InMemoryTenantContext::class)->switchTo(TenantId::fromString($this->tenant));
    }
}
