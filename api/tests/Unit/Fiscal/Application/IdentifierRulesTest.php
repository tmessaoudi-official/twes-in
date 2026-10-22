<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Application;

use App\Fiscal\Application\Preset\IdentifierRefusal;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Fiscal\Infrastructure\Preset\YamlFiscalPresets;
use App\Shared\Domain\Service\RegistrationNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The shipped presets' identifier rules, for the two holders a preset can require something of. */
final class IdentifierRulesTest extends TestCase
{
    private const string SHIPPED = __DIR__.'/../../../../config/fiscal';

    public function testAFrenchCompanyCarriesItsSirenAndSiretWithTheirCheckDigits(): void
    {
        $fr = (new YamlFiscalPresets(self::SHIPPED, \App\Tests\Support\ShippedLanguages::catalogue()))->get('FR');

        self::assertNull(IdentifierRules::refusal($fr, ['siren' => '732829320', 'siret' => '73282932000074', 'vat_number' => 'FR44732829320'], IdentifierRules::COMPANY));
        self::assertSame('identifiers.siret', IdentifierRules::refusal($fr, ['siren' => '732829320'], IdentifierRules::COMPANY)?->field);
        self::assertEquals(
            new IdentifierRefusal('identifiers.siren', 'This siren fails its check digits.', 'identifier_checksum', ['identifier' => 'siren', 'example' => '123456782']),
            IdentifierRules::refusal($fr, ['siren' => '732829321', 'siret' => '73282932000074'], IdentifierRules::COMPANY),
        );
        self::assertSame('identifiers.vat_number', IdentifierRules::refusal($fr, ['siren' => '732829320', 'siret' => '73282932000074', 'vat_number' => 'FR45732829320'], IdentifierRules::COMPANY)?->field);
    }

    public function testABusinessCustomerNeedsItsSirenButNotASiret(): void
    {
        $fr = (new YamlFiscalPresets(self::SHIPPED, \App\Tests\Support\ShippedLanguages::catalogue()))->get('FR');

        self::assertNull(IdentifierRules::refusal($fr, ['siren' => '732829320'], IdentifierRules::BUSINESS_CUSTOMER));
        self::assertEquals(
            new IdentifierRefusal('identifiers.siren', 'The FR preset requires a business customer to carry its siren.', 'identifier_required', ['identifier' => 'siren', 'example' => '123456782']),
            IdentifierRules::refusal($fr, [], IdentifierRules::BUSINESS_CUSTOMER),
        );
    }

    public function testACustomerHoldsAnotherMemberStatesVatNumberCheckedAgainstThatStatesPattern(): void
    {
        $fr = (new YamlFiscalPresets(self::SHIPPED, \App\Tests\Support\ShippedLanguages::catalogue()))->get('FR');

        foreach (['DE123456789', 'BE0123456789', 'ATU12345678', 'EL123456789', 'ESX1234567A', 'ES12345678Z', 'NL123456789B01', 'IE1234567WA', 'XI123456789'] as $number) {
            self::assertNull(IdentifierRules::refusal($fr, ['vat_number' => $number], ''), $number);
        }
        self::assertNull(IdentifierRules::refusal($fr, ['siren' => '732829320', 'vat_number' => 'DE123456789'], IdentifierRules::BUSINESS_CUSTOMER));
        foreach (['DE12345678', 'BE2123456789', 'AT12345678', 'GR123456789', 'ES123456789', 'QQ123456789'] as $number) {
            $shape = IdentifierRules::refusal($fr, ['vat_number' => $number], '');
            self::assertSame(['identifiers.vat_number', 'identifier_shape'], [$shape?->field, $shape?->reason], $number);
        }
        // A French number is still France's, with its key.
        self::assertSame('identifier_checksum', IdentifierRules::refusal($fr, ['vat_number' => 'FR45732829320'], '')?->reason);
    }

    public function testTheCompanysOwnVatNumberKeepsItsPresetsPattern(): void
    {
        $fr = (new YamlFiscalPresets(self::SHIPPED, \App\Tests\Support\ShippedLanguages::catalogue()))->get('FR');

        $refusal = IdentifierRules::refusal($fr, ['siren' => '732829320', 'siret' => '73282932000074', 'vat_number' => 'DE123456789'], IdentifierRules::COMPANY);
        self::assertSame(['identifiers.vat_number', 'identifier_shape'], [$refusal?->field, $refusal?->reason]);
    }

    public function testTheFormIsGivenOnePatternTheHolderMayMatch(): void
    {
        $vat = array_values(array_filter((new YamlFiscalPresets(self::SHIPPED, \App\Tests\Support\ShippedLanguages::catalogue()))->get('FR')->identifiers, static fn ($identifier) => 'vat_number' === $identifier->key))[0];

        self::assertSame('^FR[0-9A-Z]{2}[0-9]{9}$', $vat->patternFor(IdentifierRules::COMPANY));
        $any = '#'.$vat->patternFor(IdentifierRules::BUSINESS_CUSTOMER).'#';
        self::assertSame([1, 1, 1, 0, 0], [preg_match($any, 'FR44732829320'), preg_match($any, 'DE123456789'), preg_match($any, 'XI123456789'), preg_match($any, 'xDE123456789'), preg_match($any, 'DE123456789x')]);
    }

    public function testSomeoneThePresetRequiresNothingOfMayCarryNothingButOnlyWhatItKnows(): void
    {
        $tn = (new YamlFiscalPresets(self::SHIPPED, \App\Tests\Support\ShippedLanguages::catalogue()))->get('TN');

        self::assertNull(IdentifierRules::refusal($tn, [], ''));
        $unknown = IdentifierRules::refusal($tn, ['siren' => '732829320'], '');
        self::assertSame(['identifiers.siren', 'unknown_identifier', ['identifier' => 'siren']], [$unknown?->field, $unknown?->reason, $unknown?->params]);
        $short = IdentifierRules::refusal($tn, ['matricule_fiscal' => '1234567'], '');
        self::assertSame(['identifiers.matricule_fiscal', 'identifier_part', ['identifier' => 'matricule_fiscal', 'example' => '1234567A/B/M/000', 'part' => 'key']], [$short?->field, $short?->reason, $short?->params]);
    }

    /** @return iterable<string, array{string}> */
    public static function matriculeWritings(): iterable
    {
        yield 'compact, in small letters' => ['1234567aam000'];
        yield 'a slash after the digits' => ['1234567/A/A/M/000'];
        yield 'spaced out' => ['1234567 A A M 000'];
        yield 'dots and hyphens' => ['1234567.A-A.M-000'];
        yield 'as stored' => ['1234567A/A/M/000'];
    }

    #[DataProvider('matriculeWritings')]
    public function testEveryWritingOfAMatriculeIsStoredTheOneWay(string $written): void
    {
        $tn = (new YamlFiscalPresets(self::SHIPPED, \App\Tests\Support\ShippedLanguages::catalogue()))->get('TN');
        $kept = IdentifierRules::normalised($tn, RegistrationNumbers::kept(['matricule_fiscal' => $written]), IdentifierRules::COMPANY);

        self::assertSame(['matricule_fiscal' => '1234567A/A/M/000'], $kept);
        self::assertNull(IdentifierRules::refusal($tn, $kept, IdentifierRules::COMPANY));
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function matriculeFaults(): iterable
    {
        yield 'no key letter' => ['1234567/B/M/000', 'identifier_part', ['part' => 'key']];
        yield 'a key letter never used' => ['1234567I/B/M/000', 'identifier_part', ['part' => 'key']];
        yield 'a VAT code outside its set' => ['1234567A/F/M/000', 'identifier_part', ['part' => 'vat_code']];
        yield 'a category outside its set' => ['1234567A/B/X/000', 'identifier_part', ['part' => 'category']];
        yield 'six digits' => ['123456A/B/M/000', 'identifier_part', ['part' => 'number']];
        yield 'two establishment digits' => ['1234567A/B/M/00', 'identifier_part', ['part' => 'establishment']];
        yield 'a secondary establishment not marked E' => ['1234567A/B/M/001', 'identifier_part_rule', ['part' => 'category', 'rule' => 'secondary_establishment']];
        yield 'read from right to left, with slashes' => ['000/M/B/A1234567', 'identifier_reversed', []];
        yield 'read from right to left, each group as written' => ['000/M/B/1234567A', 'identifier_reversed', []];
        yield 'read from right to left, compact' => ['000MBA1234567', 'identifier_reversed', []];
    }

    /** @param array<string, string> $params */
    #[DataProvider('matriculeFaults')]
    public function testAMatriculeThatCannotBeReadNamesWhatIsWrongAndShowsTheExample(string $written, string $reason, array $params): void
    {
        $tn = (new YamlFiscalPresets(self::SHIPPED, \App\Tests\Support\ShippedLanguages::catalogue()))->get('TN');
        $values = IdentifierRules::normalised($tn, RegistrationNumbers::kept(['matricule_fiscal' => $written]), IdentifierRules::COMPANY);
        $refusal = IdentifierRules::refusal($tn, $values, IdentifierRules::COMPANY);

        self::assertSame(['matricule_fiscal' => strtoupper($written)], $values, 'a matricule that cannot be read is kept as written');
        self::assertSame(['identifiers.matricule_fiscal', $reason, ['identifier' => 'matricule_fiscal', 'example' => '1234567A/B/M/000', ...$params]], [$refusal?->field, $refusal?->reason, $refusal?->params]);
    }

    public function testASecondaryEstablishmentMarkedEIsRead(): void
    {
        $tn = (new YamlFiscalPresets(self::SHIPPED, \App\Tests\Support\ShippedLanguages::catalogue()))->get('TN');

        self::assertNull(IdentifierRules::refusal($tn, ['matricule_fiscal' => '1234567A/B/E/001'], IdentifierRules::COMPANY));
        self::assertNull(IdentifierRules::refusal($tn, ['matricule_fiscal' => '1234567A/B/E/000'], IdentifierRules::COMPANY));
    }
}
