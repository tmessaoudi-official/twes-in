<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Application;

use App\Fiscal\Application\Preset\IdentifierRules;
use App\Fiscal\Infrastructure\Preset\YamlFiscalPresets;
use PHPUnit\Framework\TestCase;

/** The shipped presets' identifier rules, for the two holders a preset can require something of. */
final class IdentifierRulesTest extends TestCase
{
    private const string SHIPPED = __DIR__.'/../../../../config/fiscal';

    public function testAFrenchCompanyCarriesItsSirenAndSiretWithTheirCheckDigits(): void
    {
        $fr = (new YamlFiscalPresets(self::SHIPPED))->get('FR');

        self::assertNull(IdentifierRules::refusal($fr, ['siren' => '732829320', 'siret' => '73282932000074', 'vat_number' => 'FR44732829320'], IdentifierRules::COMPANY));
        self::assertSame('identifiers.siret', IdentifierRules::refusal($fr, ['siren' => '732829320'], IdentifierRules::COMPANY)[0] ?? null);
        self::assertSame(['identifiers.siren', 'This siren fails its check digits.'], IdentifierRules::refusal($fr, ['siren' => '732829321', 'siret' => '73282932000074'], IdentifierRules::COMPANY));
        self::assertSame('identifiers.vat_number', IdentifierRules::refusal($fr, ['siren' => '732829320', 'siret' => '73282932000074', 'vat_number' => 'FR45732829320'], IdentifierRules::COMPANY)[0] ?? null);
    }

    public function testABusinessCustomerNeedsItsSirenButNotASiret(): void
    {
        $fr = (new YamlFiscalPresets(self::SHIPPED))->get('FR');

        self::assertNull(IdentifierRules::refusal($fr, ['siren' => '732829320'], IdentifierRules::BUSINESS_CUSTOMER));
        self::assertSame(
            ['identifiers.siren', 'The FR preset requires a business customer to carry its siren.'],
            IdentifierRules::refusal($fr, [], IdentifierRules::BUSINESS_CUSTOMER),
        );
    }

    public function testSomeoneThePresetRequiresNothingOfMayCarryNothingButOnlyWhatItKnows(): void
    {
        $tn = (new YamlFiscalPresets(self::SHIPPED))->get('TN');

        self::assertNull(IdentifierRules::refusal($tn, [], ''));
        self::assertSame('identifiers.siren', IdentifierRules::refusal($tn, ['siren' => '732829320'], '')[0] ?? null);
        self::assertSame('identifiers.matricule_fiscal', IdentifierRules::refusal($tn, ['matricule_fiscal' => '1234567'], '')[0] ?? null);
    }
}
