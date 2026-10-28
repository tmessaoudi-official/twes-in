<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Service\ContactRules;
use App\Shared\Domain\ValueObject\Refusal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;

/**
 * The rules a contact value is held to, in the domain, refuse what the form's constraints refused before them and accept
 * what they accepted: a check moved into the domain must not make the form, or a file, quietly weaker or stricter.
 */
final class ContactRulesTest extends TestCase
{
    /** @return iterable<string, array{string, string, Constraint}> */
    public static function values(): iterable
    {
        $email = new Assert\Email();
        foreach (['ali@exemple.tn', 'a.b+c@sous.exemple.com', 'x@y.co', 'a@b', 'a@b.', '@b.tn', 'a b@c.tn', 'a@-b.tn', 'a@[127.0.0.1]', 'a@b..tn', 'é@b.tn', 'a@b.c'] as $value) {
            yield "email $value" => ['email', $value, $email];
        }
        $url = new Assert\Url(requireTld: true);
        foreach (['https://exemple.tn', 'http://www.exemple.com/chemin?x=1', 'https://exemple', 'ftp://exemple.tn', 'javascript:alert(1)', 'mailto:a@b.tn', 'exemple.tn', 'https://', 'https://sous.exemple.co.uk:8443/a', 'https://exemple.t', 'https://ex ample.tn', 'https://192.168.0.1', 'https://user:pass@exemple.tn', 'https://ex_ample.tn', 'https://exemple.tn.', 'https://123.456', 'https://exemple.tn/chemin avec espace', 'https://xn--exmple-cva.tn', 'https://exémple.tn', 'HTTPS://EXEMPLE.TN'] as $value) {
            yield "website $value" => ['website', $value, $url];
        }
        $phone = new Assert\Regex(pattern: '/^'.ContactRules::PHONE_PATTERN.'$/u');
        foreach (['+216 71 000 000', '(71) 123-456', '12', 'abc', '+33.1.23.45.67.89', '++216', "71000000\n"] as $value) {
            yield "phone $value" => ['phone', $value, $phone];
        }
        $bic = new Assert\Bic(mode: Assert\Bic::VALIDATION_MODE_CASE_INSENSITIVE);
        foreach (['BIATTNTT', 'biattntt', 'BNPAFRPPXXX', 'BIATZZTT', 'BIAT1NTT', 'BIATTNT', 'BNPAFRPPXX', 'BIAT TN TT', ' BIATTNTT', 'BKOSXKPR', 'BIAT-TNTT'] as $value) {
            yield "bic $value" => ['bic', $value, $bic];
        }
    }

    /** The domain is asked what it would keep: the value without its surrounding spaces. */
    #[DataProvider('values')]
    public function testADomainRuleAgreesWithTheConstraintTheFormHeldTheValueTo(string $rule, string $value, Constraint $constraint): void
    {
        $formAccepts = 0 === \count(Validation::createValidator()->validate($value, $constraint));

        self::assertSame($formAccepts, null === ContactRules::$rule(trim($value)), $formAccepts ? 'refused what the form accepted' : 'accepted what the form refused');
    }

    public function testTheCountriesAreTheStandardsOwnList(): void
    {
        $codes = Countries::getCountryCodes();
        sort($codes);

        self::assertSame($codes, ContactRules::COUNTRIES);
        self::assertNull(ContactRules::country('TN'));
        self::assertSame('invalid_country', ContactRules::country('XX')?->reason);
        self::assertSame('invalid_country', ContactRules::country('tn')?->reason, 'a country is written in capitals; the address puts it so');
    }

    /** The domain holds an IBAN to its standard's shape and checksum; the form adds each country's length on top. */
    public function testAnIbanIsHeldToItsShapeAndChecksumAndRefusedWhereTheFormRefusesIt(): void
    {
        $iban = new Assert\Iban();
        foreach (['TN5910006035183598478831', 'TN59 1000 6035 1835 9847 8831', 'FR1420041010050500013M02606', 'DE89370400440532013000'] as $valid) {
            self::assertNull(ContactRules::iban($valid), $valid);
        }
        foreach (['TN5910006035183598478832', 'XX5910006035183598478831', 'TN59', 'TN59100060351835984788!1', '5910006035183598478831TN'] as $invalid) {
            self::assertSame('invalid_iban', ContactRules::iban($invalid)?->reason, $invalid);
            self::assertNotCount(0, Validation::createValidator()->validate($invalid, $iban), "the form refuses $invalid too");
        }
    }

    /** A Tunisian RIB is the IBAN TN59 spells before it (domain-identifiers § 2), so one written bare is taken as that IBAN. */
    public function testABareTunisianRibIsTheIbanItSpells(): void
    {
        foreach (['10006035183598478831', '10 006 035 1835984788 31', '10.006.035.1835984788.31', '10-006-035-1835984788-31'] as $rib) {
            self::assertSame('TN5910006035183598478831', ContactRules::account($rib), $rib);
        }
        self::assertNull(ContactRules::iban(ContactRules::account('10006035183598478831')));
        self::assertSame('invalid_iban', ContactRules::iban(ContactRules::account('10006035183598478832'))?->reason, 'a RIB whose key is wrong spells an IBAN whose check fails');
        foreach ([['TN59 1000 6035 1835 9847 8831', 'TN5910006035183598478831'], ['fr1420041010050500013m02606', 'FR1420041010050500013M02606'], ['1000603518359847883', '1000603518359847883'], ['100060351835984788311', '100060351835984788311']] as [$written, $kept]) {
            self::assertSame($kept, ContactRules::account($written), "$written is not a bare RIB");
        }
    }

    /**
     * A figure copied from a hint, a refusal or a printed document keeps its groups apart with a no-break space (U+00A0,
     * U+202F), which the rule reads as the space it is: kept between a phone's groups, dropped from a bank code.
     */
    public function testASpaceOtherThanTheKeyboardsBetweenGroupsReadsAsASpace(): void
    {
        foreach (["+216\u{202F}71\u{202F}900\u{202F}868", "+216\u{00A0}71 900 868", "(71)\u{2009}123-456", "+33\u{3000}1 23 45 67 89"] as $phone) {
            self::assertNull(ContactRules::phone($phone), $phone);
        }
        self::assertSame('invalid_phone', ContactRules::phone("+216\u{200B}71")?->reason, 'a zero-width mark is no space');

        foreach (["TN59\u{202F}1000\u{202F}6035\u{202F}1835\u{202F}9847\u{202F}8831", "tn59\u{00A0}1000 6035 1835 9847 8831"] as $iban) {
            self::assertSame('TN5910006035183598478831', ContactRules::account($iban), $iban);
            self::assertNull(ContactRules::iban($iban), $iban);
        }
        self::assertSame('TN5910006035183598478831', ContactRules::account("10\u{202F}006\u{202F}035\u{202F}1835984788\u{202F}31"), 'a bare RIB the same');

        self::assertNull(ContactRules::bic("BIAT\u{202F}TN\u{202F}TT"));
        self::assertNull(ContactRules::bic("BNOR\u{00A0}FR\u{00A0}PP\u{00A0}BDX"));
    }

    public function testATextIsHeldToItsLengthInCharactersNotBytes(): void
    {
        self::assertNull(ContactRules::text(str_repeat('é', 20), 20));
        $refused = ContactRules::text(str_repeat('é', 21), 20);

        self::assertEquals(new Refusal('invalid_text', 'At most 20 characters.', ['max' => 20]), $refused);
    }
}
