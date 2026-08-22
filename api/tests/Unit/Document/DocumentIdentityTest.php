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
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Document\DocumentIdentity;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\VatRoundingPoint;

/**
 * **WHAT IS LEFT HERE AFTER THE RULE MOVED, AND WHY IT IS NOT NOTHING.**
 *
 * The id-shape cases this class used to own moved with the rule to `Unit\Shared\IdentifierTest` when
 * `isWellFormedId()` became {@see \Twes\Domain\Shared\Identifier::isWellFormed()} — the rule was never
 * document-specific, and a `Client` id obeys it identically. What stays is the part that IS about a document:
 * that this constructor **delegates** to that predicate rather than carrying a second opinion beside it.
 *
 * **The delegation is asserted through one representative refusal rather than the whole provider**, and that is
 * deliberate: the constructor delegates, so re-running twenty shapes through it would assert the delegation
 * twenty times. What matters is that it delegates at all — replace the call with an inline copy of the pattern
 * and this stays green, but the `IdentifierTest` cases then become the only definition, which is the state both
 * extractions were for.
 *
 * **The refusal is checked in BOTH directions**, because a constructor that refused everything would satisfy the
 * refusal case alone.
 */
#[CoversClass(DocumentIdentity::class)]
final class DocumentIdentityTest extends TestCase
{
    private const CANONICAL = '0199a5b2-0000-7000-8000-0000000004aa';

    /**
     * One shape per rule the predicate enforces, so this stays a delegation test and not a second shape suite.
     *
     * Three rather than one, because a mutant that inlines a WEAKER pattern — dropping `/D`, dropping `^`, adding
     * `i` — is exactly the failure the extraction exists to prevent, and a single uppercase case kills only the
     * third of those.
     */
    #[TestWith(['0199A5B2-0000-7000-8000-0000000004AA', 'uppercase'])]
    #[TestWith(["0199a5b2-0000-7000-8000-0000000004aa\n", 'a trailing newline'])]
    #[TestWith(['junk0199a5b2-0000-7000-8000-0000000004aa', 'a prepended payload'])]
    public function testTheConstructorRefusesWhatThePredicateRefuses(string $id, string $why): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('canonical lowercase-hyphenated UUID');

        new DocumentIdentity($id, DocumentType::Invoice, VatRoundingPoint::PerRateGroup);
    }

    /** And accepts the canonical one, so the cases above are not passing because the constructor refuses everything. */
    public function testTheConstructorAcceptsTheCanonicalForm(): void
    {
        $identity = new DocumentIdentity(self::CANONICAL, DocumentType::Invoice, VatRoundingPoint::PerRateGroup);

        self::assertSame(self::CANONICAL, $identity->id);
    }

    /** The other two fields are carried through unchanged — they are the reason this type exists at all. */
    public function testTheDocumentConfigurationIsCarried(): void
    {
        $identity = new DocumentIdentity(self::CANONICAL, DocumentType::Invoice, VatRoundingPoint::PerLine);

        self::assertSame(DocumentType::Invoice, $identity->type);
        self::assertSame(VatRoundingPoint::PerLine, $identity->vatRoundingPoint);
    }
}
