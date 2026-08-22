<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\UI\Http\ApiResource;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Shared\Identifier;
use Twes\UI\Http\ApiResource\NewInvoiceInput;

/**
 * The edge's idea of an identifier must be the DOMAIN's idea of one.
 *
 * `NewInvoiceInput::CANONICAL_ID` restates a rule whose original lives in {@see Identifier}, because that class's
 * copy is `private` and belongs to `Domain/` — publishing it so a transport could borrow it would widen a domain
 * API for the convenience of a layer above it. Restating it is therefore deliberate, and this class is the price:
 * two statements of one rule need something that fails when they stop agreeing.
 *
 * **THE FAILURE THIS PREVENTS IS A STATUS CODE, not a cosmetic mismatch.** The edge regex decides whether a bad
 * `clientId` is answered with a 422 naming the field or accepted and passed on to `Invoice::withClient()`, which
 * raises an `\InvalidArgumentException` from inside the handler — outside `CreateInvoiceProcessor`'s `try`, and
 * therefore a 500. A drift in either direction turns a caller's typo into "the server broke".
 */
#[CoversClass(NewInvoiceInput::class)]
final class NewInvoiceInputTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function identifierCandidates(): iterable
    {
        yield 'a canonical lowercase UUID' => ['0199a5b2-0000-7000-8000-00000000c101'];
        yield 'v4-shaped, also canonical' => ['cccccccc-cccc-4ccc-8ccc-cccccccccccc'];

        // THE THREE THAT `#[Assert\Uuid]` WOULD HAVE ACCEPTED. Each is why this constant is a hand-written regex:
        // the domain refuses these rather than normalising them, because an identifier is a key and not a display
        // value, and two spellings of one id are two rows in any store not told they are the same.
        yield 'uppercase' => ['0199A5B2-0000-7000-8000-00000000C101'];
        yield 'mixed case' => ['0199a5b2-0000-7000-8000-00000000C101'];
        yield 'the braced form' => ['{0199a5b2-0000-7000-8000-00000000c101}'];

        yield 'not an identifier at all' => ['BANANA'];
        yield 'empty' => [''];
        yield 'the hyphens removed' => ['0199a5b2000070008000000000 00c101'];
        yield 'one character short' => ['0199a5b2-0000-7000-8000-00000000c10'];
        yield 'one character long' => ['0199a5b2-0000-7000-8000-00000000c1011'];
        yield 'a non-hex letter in range' => ['0199a5g2-0000-7000-8000-00000000c101'];

        // A TRAILING NEWLINE, which is what the `/D` modifier is for. Without it PCRE lets `$` match before a final
        // newline, so `"<uuid>\n"` would pass the edge and be refused by the domain — a 500 produced by one
        // invisible character. Both spellings of the rule carry `/D`; this case is what proves both still do.
        yield 'a trailing newline' => ["0199a5b2-0000-7000-8000-00000000c101\n"];
    }

    #[DataProvider('identifierCandidates')]
    public function testTheEdgeAndTheDomainAgreeOnWhatAnIdentifierIs(string $candidate): void
    {
        self::assertSame(
            Identifier::isWellFormed($candidate),
            1 === preg_match(NewInvoiceInput::CANONICAL_ID, $candidate),
            \sprintf(
                'The edge and the domain disagree about %s. They state one rule twice, and a disagreement here is '
                . 'a 500 where a 422 was intended (or a refusal the client cannot see the reason for).',
                json_encode($candidate),
            ),
        );
    }
}
