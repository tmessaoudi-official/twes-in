<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Functional\Http;

use ApiPlatform\Metadata\Get;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twes\Domain\Document\DocumentIdentity;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\DocumentNumber;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\InvoiceRepository;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\PersistedInvoice;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\UI\Http\ApiResource\InvoiceResource;
use Twes\UI\Http\State\InvoiceProvider;

/**
 * THE INVOICE READ REPRESENTATION — what goes on the wire, and what must not.
 *
 * Driven through a fake repository rather than a booted kernel: the subject is the TRANSLATION, and a kernel round
 * trip would prove routing and serialisation instead. `DoctrineInvoiceRepositoryTest` covers the repository against
 * a real schema; this covers what the provider does with what it returns.
 *
 * The fake implements the domain PORT, which is the point of having one — no mock framework, no partial double, and
 * the test cannot drift from the interface because PHP would refuse to compile it.
 */
#[CoversClass(InvoiceProvider::class)]
final class InvoiceProviderTest extends TestCase
{
    private const DOCUMENT = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

    /**
     * EVERY MONETARY AND QUANTITY FIELD IS A JSON **STRING**, NOT A JSON NUMBER. The contract decision this whole
     * resource rests on, asserted where it is actually observable.
     *
     * **The first version of this test called `assertIsString()` on each field and PHPStan refused it** — the DTO
     * properties are declared `string`, so every one of those assertions was provably true and therefore dead. That
     * is the half-hollow-assertion class `CLAUDE.md` records its PHPStan configuration catching before, and the
     * lesson is the useful part: the type declaration IS the enforcement, so a test that re-checks it proves
     * nothing. What is NOT type-enforced is the WIRE FORM, so that is what this asserts.
     *
     * Why it matters: JSON has one number type and it is a double. `0.100 TND` — exactly 100 millimes, Tunisia's
     * stamp duty — stops being exact the moment it becomes a float, and a client parsing `19.99` into one has
     * already lost the guarantee `Money` exists to provide. `CLAUDE.md` records money-is-never-a-float as
     * unfixable-later and names upstream's `double` columns as the worst defect in the product twes-in learns from.
     */
    public function testEveryAmountAndQuantityIsAJsonStringRatherThanAJsonNumber(): void
    {
        $json = json_encode($this->represent($this->tndInvoice()), \JSON_THROW_ON_ERROR);

        // The stamp duty, on the wire, QUOTED. An unquoted `0.1` here would be the defect.
        self::assertStringContainsString('"amount":"0.100"', $json, 'the charge amount is a quoted string');

        // And no monetary or quantity field anywhere in the payload is a bare number. Derived from the payload
        // rather than from a list of field names, so a field added later cannot slip through unquoted.
        foreach (['quantity', 'unitNet', 'vatRate', 'net', 'vat', 'amount', 'subtotalNet', 'vatTotal', 'total'] as $field) {
            self::assertDoesNotMatchRegularExpression(
                '/"' . $field . '":[^"]/',
                $json,
                \sprintf('"%s" must be a quoted decimal string, never a JSON number: %s', $field, $json),
            );
        }
    }

    /**
     * PER-LINE VAT IS THE ALLOCATED SHARE, and the shares sum EXACTLY to the group total.
     *
     * The invariant of the largest-remainder rule, and unfixable-later: under `PerRateGroup` the group's VAT is
     * rounded ONCE on the summed base, so the rounded per-line figures do not add up to it on their own and a share
     * must be allocated. Asserting the SUM rather than any individual figure is the right assertion — it is the
     * property, whereas a specific line's share depends on the tie-breaking rule.
     */
    public function testThePerLineVatSharesSumExactlyToTheVatTotal(): void
    {
        $resource = $this->represent($this->tndInvoice());

        $summed = '0';

        foreach ($resource->lines as $line) {
            $summed = bcadd($summed, $line->vat, 6);
        }

        self::assertSame(
            0,
            bccomp($summed, $resource->totals->vatTotal, 6),
            'the allocated per-line shares must sum EXACTLY to the group total — that is the whole rule',
        );
    }

    /** A draft carries neither half of a number; an issued document carries both. */
    public function testADraftHasNoNumberAndAnIssuedDocumentHasBoth(): void
    {
        $draft = $this->represent($this->tndInvoice());
        self::assertNull($draft->number, 'rendered number');
        self::assertNull($draft->sequence, 'sequence');
        self::assertSame('draft', $draft->state);

        $issued = $this->represent(
            $this->tndInvoice()->issue(new DocumentNumber(DocumentType::Invoice, NumberPattern::padded(7), 41)),
        );
        self::assertSame('0000041', $issued->number, 'the PERSISTED rendered string, printable verbatim');
        self::assertSame(41, $issued->sequence, 'the sequence, which identifies');
        self::assertSame('issued', $issued->state);
    }

    /**
     * THE ROUNDING POINT IS SERVED, because it is persisted per document.
     *
     * A company changing its setting must not restate a document a client already holds, so the value travels with
     * the document — and a client that re-computes a total to preview it needs to know which rule to apply.
     */
    public function testTheRoundingPointTravelsWithTheDocument(): void
    {
        self::assertSame(
            'per_line',
            $this->represent($this->tndInvoice(), VatRoundingPoint::PerLine)->totals->vatRoundingPoint,
        );
        self::assertSame(
            'per_rate_group',
            $this->represent($this->tndInvoice())->totals->vatRoundingPoint,
        );
    }

    /**
     * A MISSING DOCUMENT IS A 404 — and so is one belonging to ANOTHER TENANT, indistinguishably.
     *
     * That is the design of row-level security rather than a limitation of it: an error naming the document would
     * confirm its existence to a tenant not entitled to know it exists.
     */
    public function testAnAbsentOrForeignDocumentIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('No such invoice.');

        new InvoiceProvider($this->repositoryReturning(null))
            ->provide(new Get(), ['id' => self::DOCUMENT]);
    }

    /**
     * AN ILL-FORMED ID IS ALSO A 404, NOT A 400, and that is deliberate rather than lazy.
     *
     * Distinguishing "malformed" from "absent" tells an unauthenticated prober that its guess had the right SHAPE,
     * which is a small existence oracle for free. Both answers are "no such document".
     */
    public function testAnIllFormedIdIsAlsoNotFoundRatherThanABadRequest(): void
    {
        $repository = new class implements InvoiceRepository {
            public function save(DocumentIdentity $identity, Invoice $invoice): void
            {
                throw new \LogicException('not under test');
            }

            public function find(string $id): ?PersistedInvoice
            {
                throw new \InvalidArgumentException('A document id must be a canonical lowercase-hyphenated UUID.');
            }
        };

        $this->expectException(NotFoundHttpException::class);

        new InvoiceProvider($repository)->provide(new Get(), ['id' => 'NOT-A-UUID']);
    }

    /** A non-string route value is a 404 too, not a TypeError reaching the client as a 500. */
    public function testANonStringIdIsNotFound(): void
    {
        $this->expectException(NotFoundHttpException::class);

        new InvoiceProvider($this->repositoryReturning(null))->provide(new Get(), ['id' => 42]);
    }

    private function tndInvoice(): Invoice
    {
        $tnd = Currency::of('TND');

        // TWO LINES AT THE SAME RATE, which is what makes the allocation case real: one line per rate group would
        // make the allocated share trivially equal to the group total and the sum assertion vacuous.
        return Invoice::draft($tnd)
            ->withLine(new DocumentLine('3', Money::of('1.234', $tnd), Rate::fromPercentage('19')))
            ->withLine(new DocumentLine('7', Money::of('0.567', $tnd), Rate::fromPercentage('19')))
            ->withFixedCharge(new FixedCharge('stamp_duty', Money::of('0.100', $tnd)));
    }

    private function represent(
        Invoice $invoice,
        VatRoundingPoint $point = VatRoundingPoint::PerRateGroup,
    ): InvoiceResource {
        $identity = new DocumentIdentity(self::DOCUMENT, DocumentType::Invoice, $point);

        return new InvoiceProvider($this->repositoryReturning(new PersistedInvoice($identity, $invoice)))
            ->provide(new Get(), ['id' => self::DOCUMENT]);
    }

    private function repositoryReturning(?PersistedInvoice $persisted): InvoiceRepository
    {
        return new class ($persisted) implements InvoiceRepository {
            public function __construct(private readonly ?PersistedInvoice $persisted) {}

            public function save(DocumentIdentity $identity, Invoice $invoice): void
            {
                throw new \LogicException('The read path must not save.');
            }

            public function find(string $id): ?PersistedInvoice
            {
                return $this->persisted;
            }
        };
    }
}
