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

use ApiPlatform\Metadata\Post;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twes\Application\Document\IssueInvoice;
use Twes\Application\Document\IssueInvoiceHandler;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Settings\CompanySettings;
use Twes\Infrastructure\Tenancy\InMemoryTenantContext;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\UI\Http\ApiResource\NewInvoiceInput;
use Twes\UI\Http\ApiResource\NewInvoiceLineInput;
use Twes\UI\Http\State\CreateInvoiceProcessor;

/**
 * A company that has CONFIGURED settings gets them — through the real container, against a real database.
 *
 * **THIS IS THE TEST THE SETTINGS TABLE EXISTS FOR, and it is deliberately the first one written.** A
 * settings table that nothing consults is the hardwire relocated: `config/services.yaml` wired
 * `$numberWidth: 7` and `CreateInvoiceProcessor` passed `VatRoundingPoint::PerRateGroup` as a literal, and a
 * change that adds a table, a row class and an adapter while leaving both of those in place is indisputably
 * "more code" and observably nothing. `CLAUDE.md` § Gotchas records that shape four separate times — a guard
 * on one write path, a meta-gate reporting 33/33 for a gate that detected nothing, a permission constant no
 * code consulted, a control asserted in prose and enforced nowhere — so it is worth one test written BEFORE
 * the wiring rather than a paragraph promising the wiring is fine.
 *
 * **BOTH ASSERTIONS FAIL BEFORE THE WIRING CHANGE AND FOR THE STATED REASON**, which is the point of writing
 * it first: `testAnIssuedNumberIsRenderedAtTheConfiguredWidth()` sees seven digits because the handler is
 * built from a container parameter rather than from this tenant's row, and
 * `testANewDocumentTakesTheConfiguredDefaultRoundingPoint()` sees `per_rate_group` because the processor
 * names it as a literal. Neither fails because a class is missing, which would prove only that the file is
 * new.
 *
 * **IT ASSERTS THE PERSISTED COLUMNS, NOT A RETURN VALUE.** `number_rendered` and `vat_rounding_point` in the
 * `document` row are the artefacts the byte-identical-re-download guarantee is about, and a representation
 * handed back by a handler can agree with the caller while the row disagrees with both — that is exactly the
 * `NUMERIC(21,6)` lesson (§ Gotchas 2026-08-06: a create response is the document READ BACK, because
 * returning the aggregate would make `POST` and a later `GET` disagree on one document). Reading the columns
 * also means this test needs to know nothing about `PersistedInvoice`'s shape, so a refactor there cannot
 * make it pass or fail for an unrelated reason.
 *
 * **A FRESH TENANT PER TEST, generated rather than fixed.** Two cases sharing one tenant would share its
 * settings row and its document rows, and § Gotchas 2026-08-07 records two pre-existing order-dependent tests
 * found by exactly that: cases sharing one document id, so one was writing a draft over a row an earlier case
 * had issued. A generated tenant makes each case's `WHERE company_id = …` select precisely its own document,
 * which is what lets the assertions read a single row without ordering by anything.
 */
final class ConfiguredSettingsAreHonouredTest extends KernelTestCase
{
    /**
     * Deliberately NOT the default width of 7, and not adjacent to it.
     *
     * Nine digits distinguishes "the configured width was used" from "the default happened to match" and from
     * an off-by-one in padding. A test whose expected value equals the value the code would produce anyway
     * cannot fail for the reason it was written, which is the vacuity this suite's siblings keep catching.
     */
    private const int CONFIGURED_WIDTH = 9;

    /**
     * The rounding point that is NOT the default, which is the only one worth configuring in this test.
     *
     * `PerLine` was unreachable over HTTP before this table existed — the processor named `PerRateGroup` as a
     * literal and `NewInvoiceInput` has no such field, deliberately (a client may not choose how much tax a
     * document declares — ruling of 2026-08-07). So this constant is also the proof that half the calculation
     * kernel became reachable.
     */
    private const VatRoundingPoint CONFIGURED_ROUNDING_POINT = VatRoundingPoint::PerLine;

    private string $tenant;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->tenant = self::generateTenantId();
        $this->seedSettings(self::CONFIGURED_WIDTH, self::CONFIGURED_ROUNDING_POINT);
    }

    public function testAnIssuedNumberIsRenderedAtTheConfiguredWidth(): void
    {
        $this->bindTenant();

        // THROUGH THE PROCESSOR, WITH A REAL LINE. The first version of this case built the draft directly from a
        // `CreateInvoice` carrying a fixed charge and NO lines, and the aggregate refused to issue it — rightly:
        // issuing consumes a gapless number permanently, so burning one on an empty document is exactly what
        // `DocumentCannotBeIssued` exists to stop. The case then errored before reaching its own assertion, which
        // is § Gotchas 2026-07-31's "the test is not arriving" — a fixture defect wearing the costume of a
        // finding. Going through the processor also means this case and its sibling build their draft the same
        // way, so a divergence between them can only come from the settings.
        self::getContainer()->get(CreateInvoiceProcessor::class)->process(
            new NewInvoiceInput('TND', [new NewInvoiceLineInput('3', '1.234', '19')], []),
            new Post(),
        );

        self::getContainer()->get(IssueInvoiceHandler::class)->handle(new IssueInvoice($this->documentId()));

        $rendered = $this->documentColumn('number_rendered');

        self::assertNotNull($rendered, 'The document was not issued, so there is no rendered number to check.');
        self::assertSame(
            self::CONFIGURED_WIDTH,
            \strlen($rendered),
            \sprintf(
                'The issued number was rendered as "%s" (%d digits). This tenant has number_pattern_width=%d in '
                . 'company_settings, so the number should carry %d. Getting %d back means the width came from '
                . "config/services.yaml's \$numberWidth parameter rather than from this tenant's settings row — "
                . 'which is the hardwire company_settings exists to retire.',
                $rendered,
                \strlen($rendered),
                self::CONFIGURED_WIDTH,
                self::CONFIGURED_WIDTH,
                \strlen($rendered),
            ),
        );
    }

    public function testANewDocumentTakesTheConfiguredDefaultRoundingPoint(): void
    {
        $this->bindTenant();

        self::getContainer()->get(CreateInvoiceProcessor::class)->process(
            new NewInvoiceInput('TND', [new NewInvoiceLineInput('3', '1.234', '19')], []),
            new Post(),
        );

        self::assertSame(
            self::CONFIGURED_ROUNDING_POINT->value,
            $this->documentColumn('vat_rounding_point'),
            \sprintf(
                'A new document took a rounding point this tenant did not configure. company_settings holds '
                . 'default_vat_rounding_point=%s, and the document must be created with it — the value is '
                . 'snapshotted per document precisely so a later settings change cannot restate one a client '
                . 'already holds. Reading %s back means CreateInvoiceProcessor is still passing its literal.',
                self::CONFIGURED_ROUNDING_POINT->value,
                CompanySettings::defaultVatRoundingPointBackedValue(),
            ),
        );
    }

    /**
     * THE COLUMN REFUSES A WIDTH `NumberPattern` REFUSES — the guarantee that replaced a construction-time check.
     *
     * **This case exists because deleting `testAnImpossibleConfiguredWidthFailsAtConstruction()` moved a guarantee
     * and very nearly left it enforced by nothing.** While the width was a container parameter, a value outside
     * `NumberPattern`'s range blew up when the service was built. It now comes from a row, so the equivalent
     * guarantee is `CHECK (number_pattern_width BETWEEN 1 AND NumberPattern::MAX_WIDTH)` — and for one commit the
     * replacement test's own docblock asserted *"`ConfiguredSettingsAreHonouredTest` proves the database refuses
     * one"* while this class had no such case. That is the "control asserted in prose and enforced nowhere" shape
     * `CLAUDE.md` § Gotchas records four times, occurring inside the sentence explaining where the control went.
     *
     * The constraint is STRICTLY STRONGER than what it replaced: a constructor check guards one call site, and a
     * CHECK guards every writer including `psql` and a future admin tool.
     *
     * @param int $width a value the column must refuse — `0` and `MAX_WIDTH + 1` are the two bounds, and a
     *                   negative is included because "below the floor" and "not a count at all" are different
     *                   mistakes a caller can make
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('widthsTheColumnMustRefuse')]
    public function testTheColumnRefusesAWidthTheDomainWouldRefuse(int $width): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->expectExceptionMessageMatches('/company_settings_number_pattern_width_is_renderable/');

        // A DIFFERENT TENANT from `setUp()`'s, because that one already has a row and a PRIMARY KEY violation
        // would be raised BEFORE the CHECK — the test would pass on a schema with no CHECK at all, which is
        // exactly the "not arriving" failure § Gotchas 2026-07-31 records.
        $this->tenant = self::generateTenantId();
        $this->seedSettings($width, self::CONFIGURED_ROUNDING_POINT);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function widthsTheColumnMustRefuse(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'one past the maximum' => [NumberPattern::MAX_WIDTH + 1];
    }

    /**
     * THE OTHER CHECK on the same table: a rounding point the enum does not know cannot be stored.
     *
     * Nearly free beside the case above and worth having for a reason the width case does not carry: the adapter's
     * {@see \Twes\Infrastructure\Persistence\Doctrine\DoctrineCompanySettingsRepository::roundingPointFrom()}
     * throws rather than defaulting for exactly this value, and its docblock says the branch "should be
     * unreachable through any supported path" **because of this constraint**. That claim was, until this case
     * existed, proven by nothing.
     */
    public function testTheColumnRefusesARoundingPointTheEnumDoesNotKnow(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
        $this->expectExceptionMessageMatches('/company_settings_rounding_point_is_known/');

        $this->tenant = self::generateTenantId();
        $this->bindTenant();
        $connection = self::getContainer()->get(Connection::class);

        $connection->transactional(fn(): int => $connection->executeStatement(
            'INSERT INTO company_settings (company_id, number_pattern_width, default_vat_rounding_point) '
            . 'VALUES (:tenant, 7, :roundingPoint)',
            ['tenant' => $this->tenant, 'roundingPoint' => 'per_fortnight'],
        ));
    }

    /**
     * A tenant nothing else in this database has used.
     *
     * `Symfony\Component\Uid\Uuid` rather than the application's own `IdGenerator`: a fixture that generated
     * its tenant through the code under test would go wrong in the same direction as that code.
     */
    private static function generateTenantId(): string
    {
        return \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
    }

    /**
     * Put this tenant's settings in the database, through SQL rather than through the repository.
     *
     * **DELIBERATELY NOT `CompanySettingsRepository::save()`.** A fixture built with the code under test
     * cannot fail independently of it: if `save()` wrote the wrong column, this test would seed the wrong
     * value and then assert the code agreed with itself — the shape § Gotchas 2026-07-31 records as a P0,
     * where a validator read its expected column name out of the policy it was validating and therefore always
     * agreed. The adapter's own round trip is covered by its own test; this one seeds independently.
     */
    private function seedSettings(int $width, VatRoundingPoint $roundingPoint): void
    {
        $this->bindTenant();
        $connection = $this->connection();

        // The binding is written by TenantBindingMiddleware on beginTransaction(), so the INSERT below satisfies
        // the table's WITH CHECK half. Outside a transaction there is no binding and row-level security refuses
        // the write — which is the same reason the adapter requires one.
        $connection->beginTransaction();

        try {
            $connection->executeStatement(
                'INSERT INTO company_settings (company_id, number_pattern_width, default_vat_rounding_point) '
                . 'VALUES (:tenant, :width, :roundingPoint)',
                ['tenant' => $this->tenant, 'width' => $width, 'roundingPoint' => $roundingPoint->value],
            );
            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }
    }

    /**
     * The id of this tenant's only document.
     *
     * "Only" is guaranteed by the generated tenant, not assumed: each case seeds a tenant nothing else has
     * used, so a second row would itself be a defect worth failing on.
     */
    private function documentId(): string
    {
        $id = $this->documentColumn('id');

        self::assertNotNull($id, 'No document was created for this tenant, so there is nothing to issue.');

        return $id;
    }

    /**
     * One column of this tenant's single document row, read inside a bound transaction.
     *
     * The transaction is not ceremony: the binding row-level security compares against is transaction-local,
     * so a read outside one sees nothing and would report "no document" for a document that exists
     * (§ Gotchas 2026-08-07).
     */
    private function documentColumn(string $column): ?string
    {
        $this->bindTenant();
        $connection = $this->connection();
        $connection->beginTransaction();

        try {
            /** @var array<string, scalar|null>|false $row */
            $row = $connection->fetchAssociative(
                \sprintf('SELECT %s AS value FROM document WHERE company_id = :tenant', $column),
                ['tenant' => $this->tenant],
            );
            $connection->commit();
        } catch (\Throwable $failure) {
            $connection->rollBack();

            throw $failure;
        }

        if (false === $row || null === $row['value']) {
            return null;
        }

        return (string) $row['value'];
    }

    private function bindTenant(): void
    {
        self::getContainer()->get(InMemoryTenantContext::class)->switchTo(TenantId::fromString($this->tenant));
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
