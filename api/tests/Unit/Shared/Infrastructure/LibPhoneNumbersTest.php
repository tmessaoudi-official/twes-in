<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Domain\ValueObject\PhoneVerdict;
use App\Shared\Infrastructure\Intl\LibPhoneNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * A phone number judged by its country's plan: the record's country reads a national number, a leading + or 00 names
 * another, and what is kept is E.164. A wrong length is refused with the country's own example; a number of the right
 * length outside the ranges its country hands out is kept and marked to check, never refused.
 */
final class LibPhoneNumbersTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, string, ?string}> typed, country, verdict, E.164 */
    public static function numbers(): iterable
    {
        yield 'a Tunisian number written nationally' => ['71 900 868', 'TN', PhoneVerdict::VALID, '+21671900868'];
        yield 'a mobile beside it' => ['20 123 456', 'TN', PhoneVerdict::VALID, '+21620123456'];
        yield 'a French number written nationally' => ['06 12 34 56 78', 'FR', PhoneVerdict::VALID, '+33612345678'];
        yield '+ names another country than the record' => ['+216 71 900 868', 'FR', PhoneVerdict::VALID, '+21671900868'];
        yield '00 names it as + does' => ['00 216 71 900 868', 'FR', PhoneVerdict::VALID, '+21671900868'];
        yield 'with no record country, + is enough' => ['+33 1 23 45 67 89', null, PhoneVerdict::VALID, '+33123456789'];
        yield 'groups kept apart by no-break spaces, as a copied hint has them' => ["71\u{00A0}900\u{202F}868", 'TN', PhoneVerdict::VALID, '+21671900868'];
        yield 'the marks a paste leaves at its ends' => ["\u{200E}+216 71 900 868\u{200B}", null, PhoneVerdict::VALID, '+21671900868'];
        yield 'dots, dashes and brackets between the groups' => ['(71) 900-868', 'TN', PhoneVerdict::VALID, '+21671900868'];
        yield 'the right length outside the ranges handed out' => ['+216 11 111 111', null, PhoneVerdict::TO_CHECK, '+21611111111'];
        yield 'too short' => ['71 900', 'TN', PhoneVerdict::WRONG_LENGTH, null];
        yield 'too long' => ['+33 9 99 99 99 99 9', null, PhoneVerdict::WRONG_LENGTH, null];
        yield 'a national number with no country to read it by' => ['71 900 868', null, PhoneVerdict::NEEDS_COUNTRY, null];
        yield 'no number at all' => ['abc', 'TN', PhoneVerdict::NOT_A_NUMBER, null];
        yield 'letters among the digits' => ['71 900 86A', 'TN', PhoneVerdict::NOT_A_NUMBER, null];
    }

    #[DataProvider('numbers')]
    public function testANumberIsJudgedByItsCountrysPlan(string $typed, ?string $country, string $verdict, ?string $e164): void
    {
        $judged = new LibPhoneNumbers(new IdentityTranslator())->judge($typed, $country);

        self::assertSame([$verdict, $e164], [$judged->verdict, $judged->e164]);
    }

    public function testARefusedNumberCarriesItsCountrysExampleAndCallingCode(): void
    {
        $judged = new LibPhoneNumbers(new IdentityTranslator())->judge('71 900', 'TN');

        self::assertSame(['+216 30 010 123', '+216'], [$judged->example, $judged->callingCode]);
        self::assertSame('+33 1 23 45 67 89', new LibPhoneNumbers(new IdentityTranslator())->judge('01 23', 'FR')->example);
    }

    public function testAKeptNumberIsShownGroupedAsItsCountryWritesIt(): void
    {
        $phones = new LibPhoneNumbers(new IdentityTranslator());

        self::assertSame('+216 71 900 868', $phones->grouped('+21671900868'));
        self::assertSame('+33 6 12 34 56 78', $phones->grouped('+33612345678'));
        // A number stored before the rule, as it was typed, is shown as it is: nothing is guessed.
        self::assertSame('71.900.868 poste 12', $phones->grouped('71.900.868 poste 12'));
    }

    public function testAnUnknownCountryIsReadAsNoCountry(): void
    {
        self::assertSame(PhoneVerdict::NEEDS_COUNTRY, new LibPhoneNumbers(new IdentityTranslator())->judge('71 900 868', 'ZZ')->verdict);
        self::assertSame(PhoneVerdict::VALID, new LibPhoneNumbers(new IdentityTranslator())->judge('+21671900868', 'ZZ')->verdict);
    }
}
