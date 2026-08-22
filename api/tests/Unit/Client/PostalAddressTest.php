<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Client;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Twes\Domain\Client\PostalAddress;

/**
 * The buyer's postal address — EN 16931's BG-8, and the three refusals that make it one.
 *
 * **THE ALL-OR-NOTHING PROPERTY IS THE POINT OF THE TYPE.** A `Client` may legitimately have no address at all
 * — it is created before anyone has asked for one — but a HALF address is not a state anybody wants: an
 * invoice carrying a city and no country is one nobody can post and no e-invoicing profile will validate. So
 * absence is modelled by the address being `null` on the client, never by a `PostalAddress` whose required
 * parts are blank, and the constructor is what makes that unrepresentable.
 */
#[CoversClass(PostalAddress::class)]
final class PostalAddressTest extends TestCase
{
    private static function address(
        string $line1 = '12 Rue de la Paix',
        ?string $line2 = null,
        ?string $postcode = '75002',
        string $city = 'Paris',
        string $countryCode = 'FR',
    ): PostalAddress {
        return new PostalAddress($line1, $line2, $postcode, $city, $countryCode);
    }

    public function testAFullAddressIsCarriedThrough(): void
    {
        $address = self::address(line2: 'Bâtiment C');

        self::assertSame('12 Rue de la Paix', $address->line1);
        self::assertSame('Bâtiment C', $address->line2);
        self::assertSame('75002', $address->postcode);
        self::assertSame('Paris', $address->city);
        self::assertSame('FR', $address->countryCode);
    }

    /**
     * **A POSTCODE IS OPTIONAL BECAUSE SOME COUNTRIES HAVE NONE.**
     *
     * Ireland had no general postcode system until 2015 and several jurisdictions still have none; refusing a
     * blank one would make those clients unrepresentable. `line2` is optional for the ordinary reason.
     */
    public function testTheOptionalPartsMayBeAbsent(): void
    {
        $address = self::address(line2: null, postcode: null);

        self::assertNull($address->line2);
        self::assertNull($address->postcode);
    }

    /**
     * **BLANK IS NORMALISED TO ABSENT, NOT KEPT.**
     *
     * `''`, `'   '` and `null` all mean "there is no second line", and keeping them apart would put three
     * spellings of one fact in a column — the same argument `DocumentIdentity` makes for refusing two spellings
     * of one id, applied to absence instead of identity. A client edited through a web form sends `''` for an
     * emptied field, so this is the ordinary path rather than an exotic one.
     */
    #[TestWith([''])]
    #[TestWith(['   '])]
    #[TestWith(["\t\n"])]
    public function testABlankOptionalPartBecomesNull(string $blank): void
    {
        $address = self::address(line2: $blank, postcode: $blank);

        self::assertNull($address->line2);
        self::assertNull($address->postcode);
    }

    /** Every part is trimmed on STORE, so ' Paris' and 'Paris' are not two cities. */
    public function testEveryPartIsTrimmedOnStore(): void
    {
        $address = self::address(line1: '  12 Rue de la Paix  ', line2: '  Bâtiment C ', postcode: ' 75002 ', city: ' Paris ');

        self::assertSame('12 Rue de la Paix', $address->line1);
        self::assertSame('Bâtiment C', $address->line2);
        self::assertSame('75002', $address->postcode);
        self::assertSame('Paris', $address->city);
    }

    /** @return iterable<string, array{string, string}> */
    public static function blankRequiredParts(): iterable
    {
        yield 'an empty street' => ['line1', ''];
        yield 'a whitespace street' => ['line1', '   '];
        yield 'an empty city' => ['city', ''];
        yield 'a whitespace city' => ['city', "\t"];
    }

    #[DataProvider('blankRequiredParts')]
    public function testABlankRequiredPartIsRefused(string $part, string $blank): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::address(...[$part => $blank]);
    }

    /**
     * **THE COUNTRY CODE IS ISO 3166-1 ALPHA-2, REFUSED RATHER THAN NORMALISED.**
     *
     * Lowercase is refused for the reason `Identifier` refuses an uppercase UUID: two spellings of one value
     * compare unequal as strings, and this one is grouped on and reported per country. Normalising at the HTTP
     * boundary is a transport concern; the domain states the rule.
     *
     * @return iterable<string, array{string}>
     */
    public static function refusedCountryCodes(): iterable
    {
        yield 'lowercase' => ['fr'];
        yield 'mixed case' => ['Fr'];
        yield 'alpha-3' => ['FRA'];
        yield 'one letter' => ['F'];
        yield 'a digit' => ['F1'];
        yield 'empty' => [''];
        yield 'whitespace only' => ['  '];
        yield 'a country name' => ['France'];
        yield 'with a trailing newline' => ["FR\n"];
    }

    #[DataProvider('refusedCountryCodes')]
    public function testARefusedCountryCodeIsNotAccepted(string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::address(countryCode: $code);
    }

    /** Tunisia and France both, so the case above is not passing because every code is refused. */
    #[TestWith(['FR'])]
    #[TestWith(['TN'])]
    #[TestWith(['IE'])]
    public function testAWellFormedCountryCodeIsAccepted(string $code): void
    {
        self::assertSame($code, self::address(countryCode: $code)->countryCode);
    }

    /**
     * **BOUNDED, AND MEASURED IN CHARACTERS RATHER THAN BYTES.**
     *
     * `strlen()` would make the bound depend on the alphabet — an Arabic address is two bytes per character —
     * and an address refused for being in the wrong script is a defect, not a bound. The same argument
     * `FixedCharge::MAX_LABEL_LENGTH` makes, and the reason it uses `mb_strlen` with an explicit encoding.
     */
    public function testAnOverlongPartIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('characters');

        self::address(line1: str_repeat('a', PostalAddress::MAX_PART_LENGTH + 1));
    }

    /** The bound is a CEILING and not an off-by-one: exactly the maximum is accepted. */
    public function testExactlyTheMaximumIsAccepted(): void
    {
        $atTheLimit = str_repeat('é', PostalAddress::MAX_PART_LENGTH);

        self::assertSame($atTheLimit, self::address(line1: $atTheLimit)->line1);
    }

    /** A multi-byte part at the limit is accepted, which is what proves the bound is not counting bytes. */
    public function testTheBoundCountsCharactersAndNotBytes(): void
    {
        $arabic = str_repeat('ش', PostalAddress::MAX_PART_LENGTH);

        self::assertGreaterThan(PostalAddress::MAX_PART_LENGTH, \strlen($arabic));
        self::assertSame($arabic, self::address(city: $arabic)->city);
    }
}
