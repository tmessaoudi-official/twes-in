<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Domain;

use App\Fiscal\Domain\IdentifierCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The checks docs/fiscal/FR.md § 5 describes, on numbers whose check digits were computed apart from this code. */
final class IdentifierCheckTest extends TestCase
{
    /** @return iterable<string, array{IdentifierCheck, string, bool}> */
    public static function numbers(): iterable
    {
        yield 'a SIREN with its check digit' => [IdentifierCheck::Luhn, '732829320', true];
        yield 'a SIREN one digit off' => [IdentifierCheck::Luhn, '732829321', false];
        yield 'a SIREN of letters' => [IdentifierCheck::Luhn, '73282932A', false];
        yield 'a SIRET with its check digit' => [IdentifierCheck::Siret, '73282932000074', true];
        yield 'a SIRET one digit off' => [IdentifierCheck::Siret, '73282932000075', false];
        yield "La Poste's SIRET adding up to a multiple of five, which Luhn refuses" => [IdentifierCheck::Siret, '35600000000015', true];
        yield "La Poste's SIRET that Luhn accepts but does not add up to a multiple of five" => [IdentifierCheck::Siret, '35600000000048', false];
        yield 'a VAT number with its key' => [IdentifierCheck::FrenchVatKey, 'FR44732829320', true];
        yield 'a VAT number with a wrong key' => [IdentifierCheck::FrenchVatKey, 'FR45732829320', false];
        yield 'a VAT number with a key of letters, issued without a SIREN' => [IdentifierCheck::FrenchVatKey, 'FRAB732829320', true];
        yield 'a VAT number of another country' => [IdentifierCheck::FrenchVatKey, 'DE44732829320', false];
    }

    #[DataProvider('numbers')]
    public function testANumberIsCheckedByTheRuleItsPresetNames(IdentifierCheck $check, string $value, bool $accepted): void
    {
        self::assertSame($accepted, $check->accepts($value));
    }
}
