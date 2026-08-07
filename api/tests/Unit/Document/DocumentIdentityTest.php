<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Document\DocumentIdentity;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\VatRoundingPoint;

/**
 * **THE ONE DEFINITION OF A DOCUMENT ID, AND THE THREE PROPERTIES ITS DOCBLOCK CALLS LOAD-BEARING.**
 *
 * `DocumentIdentity::isWellFormedId()` was extracted by the P1 sweep as *"the ONE definition of that rule"*, consumed
 * by three call sites — this class's constructor, `DoctrineInvoiceRepository::load()` and `InvoiceProvider::provide()`
 * — and it arrived with **no test of its own**. Two of round 2's lenses found that independently, and each measured the
 * same thing: every specific rule the docblock names could be deleted with the entire suite green.
 *
 * ```
 * drop the /D modifier      -> unit OK, functional OK    SURVIVED
 * drop the leading ^ anchor -> unit OK, functional OK    SURVIVED
 * add the i modifier        -> unit OK, functional OK    SURVIVED
 * return true;              -> functional FAILURES (1)   killed
 * ```
 *
 * Only the coarsest mutant died, and it died in a *functional* test about HTTP 404s. So the extraction moved a
 * correctness rule into `Domain/` — trivially unit-testable, zero collaborators — and left its only coverage behind a
 * PostgreSQL dependency, with the two shapes the docblock names as historical defects untested in either place.
 *
 * **Each case below corresponds to one of those three properties, and each is a real defect rather than a hypothetical
 * one:**
 *
 * - `/D` — without it PCRE's `$` also matches BEFORE a final newline, so `"…e1f0\n"` is accepted. Two unequal strings
 *   for one id, and this value is used as a key.
 * - the anchors — without `^` an unanchored pattern accepts an id with a payload PREPENDED, and this value reaches a
 *   `WHERE` clause.
 * - case sensitivity — uppercase and the braced/urn forms are refused rather than normalised, because two spellings of
 *   one id compare unequal as strings.
 */
#[CoversClass(DocumentIdentity::class)]
final class DocumentIdentityTest extends TestCase
{
    private const CANONICAL = '0199a5b2-0000-7000-8000-0000000004aa';

    /** The shape everything else is a deviation from. */
    public function testTheCanonicalFormIsAccepted(): void
    {
        self::assertTrue(DocumentIdentity::isWellFormedId(self::CANONICAL));
    }

    /**
     * Every rejected spelling, each named for the rule it violates.
     *
     * A provider rather than one case per shape, because the rule is a conjunction and a reader needs to see the whole
     * refused set at once — and because a shape added later is one line rather than one method.
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedIds(): iterable
    {
        // THE `/D` CASES. A trailing newline is the one the docblock singles out, and PCRE accepts it without `/D`
        // because `$` matches before a final newline. The others are the same family: anything after the last digit.
        yield 'a trailing newline (the /D case)' => [self::CANONICAL . "\n"];
        yield 'a trailing carriage return and newline' => [self::CANONICAL . "\r\n"];
        yield 'a trailing space' => [self::CANONICAL . ' '];

        // THE ANCHOR CASES. Without `^` a payload PREPENDED is accepted; without `$` (which `/D` makes strict) one
        // APPENDED is. Both matter because this value reaches a WHERE clause and is used as a key.
        yield 'a payload prepended' => ['junk' . self::CANONICAL];
        yield 'a payload appended' => [self::CANONICAL . 'junk'];
        yield 'a newline then a second id' => ["\n" . self::CANONICAL];

        // THE CASE-SENSITIVITY CASES. Refused rather than normalised: two spellings of one id compare unequal as
        // strings, so accepting both would put two rows-worth of identity on one document.
        yield 'uppercase' => [strtoupper(self::CANONICAL)];
        yield 'mixed case' => ['0199A5B2-0000-7000-8000-0000000004AA'];

        // THE OTHER UUID SPELLINGS, which are valid UUIDs to most readers and different STRINGS to a key comparison.
        yield 'the braced form' => ['{' . self::CANONICAL . '}'];
        yield 'the urn form' => ['urn:uuid:' . self::CANONICAL];
        yield 'unhyphenated' => [str_replace('-', '', self::CANONICAL)];

        // THE GROUP-LENGTH CEILINGS, which round 3 found unpinned — `{12}` → `{12,}` and `{8}` → `{8,}` each survived
        // the whole suite. Under the first, a 37-character id is accepted, reaches `WHERE id = :id`, and PostgreSQL
        // raises `invalid input syntax for type uuid` — a 500 where this predicate exists to produce a 404. The set
        // above had "one digit short" and nothing LONGER than canonical, and a `'junk'` suffix does not kill a hex-only
        // widening because the appended text is not hex.
        yield 'last group one hex digit too long' => [self::CANONICAL . 'a'];
        yield 'last group four hex digits too long' => [self::CANONICAL . 'ffff'];
        yield 'first group one hex digit too long' => ['0199a5b22-0000-7000-8000-0000000004aa'];
        yield 'a middle group too long' => ['0199a5b2-00000-7000-8000-0000000004aa'];

        // AND THE ORDINARY REFUSALS, so the provider is not read as covering only exotic shapes.
        yield 'empty' => [''];
        yield 'not a uuid at all' => ['NOT-A-UUID'];
        yield 'one digit short' => [substr(self::CANONICAL, 0, -1)];
        yield 'a non-hex digit' => [str_replace('a', 'g', self::CANONICAL)];
    }

    #[DataProvider('refusedIds')]
    public function testARefusedSpellingIsNotWellFormed(string $id): void
    {
        self::assertFalse(
            DocumentIdentity::isWellFormedId($id),
            var_export($id, true) . ' must not be accepted as a document id',
        );
    }

    /**
     * THE CONSTRUCTOR REFUSES THE SAME SET, which is what makes the predicate the ONE definition rather than a second
     * opinion sitting beside the constructor's own check.
     *
     * Asserted through one representative refusal rather than the whole provider: the constructor delegates, so
     * re-running sixteen shapes through it would assert the delegation sixteen times. What matters is that it
     * delegates at all — replace the call with an inline copy of the pattern and this stays green, but the
     * `isWellFormedId` cases above become the only definition, which is the state the extraction was for.
     */
    public function testTheConstructorRefusesWhatThePredicateRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical lowercase-hyphenated UUID');

        new DocumentIdentity(strtoupper(self::CANONICAL), DocumentType::Invoice, VatRoundingPoint::PerRateGroup);
    }

    /** And accepts the canonical one, so the case above is not passing because the constructor refuses everything. */
    public function testTheConstructorAcceptsTheCanonicalForm(): void
    {
        $identity = new DocumentIdentity(self::CANONICAL, DocumentType::Invoice, VatRoundingPoint::PerRateGroup);

        self::assertSame(self::CANONICAL, $identity->id);
    }
}
