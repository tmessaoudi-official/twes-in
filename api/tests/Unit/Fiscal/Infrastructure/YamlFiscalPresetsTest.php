<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Infrastructure;

use App\Fiscal\Application\Preset\PresetRegime;
use App\Fiscal\Application\Preset\UnknownFiscalPreset;
use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\IdentifierCheck;
use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\TaxKind;
use App\Fiscal\Infrastructure\Preset\InvalidFiscalPreset;
use App\Fiscal\Infrastructure\Preset\YamlFiscalPresets;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The fiscal presets are data a mistake in which becomes a wrong invoice, so each file is validated when it is
 * read, and a refusal names the file and the rule it breaks (docs/SPEC.md § 3 Fiscal presets).
 */
final class YamlFiscalPresetsTest extends TestCase
{
    private const string SHIPPED = __DIR__.'/../../../../config/fiscal';
    private const string REMOVE = "\0remove";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/twes-presets-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testTheShippedPresetsLoad(): void
    {
        $presets = new YamlFiscalPresets(self::SHIPPED);

        self::assertSame(['FR', 'TN'], $presets->keys());
        $tn = $presets->get('TN');
        self::assertSame('TN', $tn->country);
        self::assertSame('TND', $tn->currency);
        self::assertSame(3, $tn->minorUnit);
        self::assertSame(RoundingPoint::PerRateGroup, $tn->vatRoundingPoint);
        self::assertSame(TaxBasis::Exclusive, $tn->taxBasis);

        $fodec = $tn->component('FODEC');
        self::assertSame(TaxKind::PercentageLine, $fodec->kind);
        self::assertSame(TaxFamily::Levy, $fodec->family);
        self::assertSame('1', $fodec->rate);
        self::assertTrue($fodec->entersVatBase);
        self::assertFalse($fodec->isDefault);
        self::assertSame('FODEC 1 %', $fodec->names['fr']);
        self::assertSame('1.000', $tn->component('TIMBRE')->amount);
        self::assertSame('1000.000', $tn->component('RS1')->threshold);
        self::assertSame(
            ['standard', 'exempt', 'suspended', 'export'],
            array_map(static fn (PresetRegime $regime) => $regime->code, $tn->customerTaxRegimes),
        );
        self::assertSame([TaxFamily::Vat], $tn->customerTaxRegimes[1]->excludedFamilies);
        self::assertSame('C62', $tn->units[0]->code);
        self::assertSame('000', $tn->establishment->defaultCode);
        self::assertSame('^[0-9]{3}$', $tn->establishment->codePattern);

        $fr = $presets->get('FR');
        self::assertSame(2, $fr->minorUnit);
        self::assertSame('00001', $fr->establishment->defaultCode);
        self::assertSame('^[0-9]{5}$', $fr->establishment->codePattern);
        self::assertSame('5.5', $fr->component('TVA5_5')->rate);
        self::assertSame('fiscal.mention.fr.franchise', $fr->companyVatRegimes[1]->mentionKey);
        self::assertSame([IdentifierCheck::Luhn, IdentifierCheck::Siret, IdentifierCheck::FrenchVatKey], array_map(static fn ($identifier) => $identifier->check, $fr->identifiers));
        self::assertNull($tn->identifiers[0]->check, "the matricule fiscal's check letter is not sourced (docs/fiscal/TN.md § 8)");
        self::assertSame(['fiscal.mention.fr.late_payment', 'fiscal.mention.fr.recovery_indemnity', 'fiscal.mention.fr.no_early_discount'], $fr->invoiceMentions);
    }

    public function testAPresetNobodyWroteIsUnknown(): void
    {
        $presets = new YamlFiscalPresets(self::SHIPPED);

        self::assertFalse($presets->has('DE'));
        self::assertTrue($presets->has('TN'));
        $this->expectException(UnknownFiscalPreset::class);
        $presets->get('DE');
    }

    public function testAnUntouchedCopyLoads(): void
    {
        // The control for the refusals below: dumping and re-reading a valid preset is not itself what breaks it.
        $this->writeTunisia(Yaml::parseFile(self::SHIPPED.'/TN.yaml'));

        self::assertSame('TND', (new YamlFiscalPresets($this->dir))->get('TN')->currency);
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function brokenPresets(): iterable
    {
        yield 'a kind outside the closed set' => ['tax_components.0.kind', 'percentage_total', 'kind'];
        yield 'a family that does not match its kind' => ['tax_components.0.family', 'stamp', 'family'];
        yield 'a percentage tax without a rate' => ['tax_components.0.rate', self::REMOVE, 'rate'];
        yield 'a rate with more decimals than a rate column holds' => ['tax_components.0.rate', '19.0001', 'rate'];
        yield 'a fixed charge written finer than the currency' => ['tax_components.4.amount', '1.0000', 'amount'];
        yield 'a fixed charge carrying a rate' => ['tax_components.4.rate', '1', 'rate'];
        yield 'a withholding without a threshold' => ['tax_components.5.threshold', self::REMOVE, 'threshold'];
        yield 'a VAT that claims to enter the VAT base' => ['tax_components.0.enters_vat_base', true, 'enters_vat_base'];
        yield 'a component code used twice' => ['tax_components.1.code', 'TVA19', 'TVA19'];
        yield 'a component code that is not a code' => ['tax_components.1.code', 'tva 13', 'code'];
        yield 'a name missing its English' => ['tax_components.0.names.en', self::REMOVE, 'en'];
        yield 'an identifier pattern that does not compile' => ['identifiers.0.pattern', '([0-9]', 'pattern'];
        yield 'an identifier check the product does not implement' => ['identifiers.0.check', 'mod23', 'check'];
        yield 'a regime excluding a family that does not exist' => ['customer_tax_regimes.1.excluded_families', ['tva'], 'excluded_families'];
        yield 'a regime code used twice' => ['customer_tax_regimes.1.code', 'standard', 'standard'];
        yield 'a translation key outside the fiscal domain' => ['customer_tax_regimes.1.mention_key', 'mention.exempt', 'mention_key'];
        yield 'a minor unit the currency does not have' => ['minor_unit', 2, 'minor_unit'];
        yield 'a currency that does not exist' => ['currency', 'XXQ', 'currency'];
        yield 'a file named for another country' => ['country', 'FR', 'country'];
        yield 'a unit with more decimals than a quantity holds' => ['units.0.decimals', 4, 'decimals'];
        yield 'a unit code used twice' => ['units.1.code', 'C62', 'C62'];
        yield 'a rounding point that does not exist' => ['rounding.vat_point', 'per_document', 'vat_point'];
        yield 'a document language the product does not speak' => ['document_languages', ['ar'], 'document_languages'];
        yield 'a numbering format without its sequence' => ['numbering.invoice.format', 'FAC-{YYYY}', 'format'];
        yield 'a numbering format with a token the product does not know' => ['numbering.invoice.format', 'FAC-{DD}-{SEQ}', 'format'];
        yield 'a reset period that does not exist' => ['numbering.invoice.reset', 'weekly', 'reset'];
        yield 'an establishment without its default code' => ['establishment.default_code', self::REMOVE, 'default_code'];
        yield 'a default establishment code outside its own pattern' => ['establishment.default_code', '12', 'default_code'];
        yield 'an establishment code pattern that does not compile' => ['establishment.code_pattern', '([0-9]', 'code_pattern'];
        yield 'an unknown key' => ['vat_rates', ['19'], 'vat_rates'];
    }

    #[DataProvider('brokenPresets')]
    public function testABrokenPresetIsRefusedNamingTheFileAndTheRule(string $path, mixed $value, string $mentioned): void
    {
        $this->writeTunisia(self::edit(Yaml::parseFile(self::SHIPPED.'/TN.yaml'), $path, $value));

        try {
            (new YamlFiscalPresets($this->dir))->get('TN');
            self::fail("A preset with $path broken was accepted.");
        } catch (InvalidFiscalPreset $refused) {
            self::assertStringContainsString('TN.yaml', $refused->getMessage());
            self::assertStringContainsString($mentioned, $refused->getMessage());
        }
    }

    private function writeTunisia(mixed $preset): void
    {
        file_put_contents($this->dir.'/TN.yaml', Yaml::dump($preset, 8, 2));
    }

    /** Sets (or removes) the value at a dotted path of a parsed preset. */
    private static function edit(mixed $data, string $path, mixed $value): mixed
    {
        if (!\is_array($data)) {
            throw new \LogicException("Nothing to edit at $path.");
        }
        $keys = explode('.', $path);
        $key = array_shift($keys);
        $key = ctype_digit($key) ? (int) $key : $key;
        if ([] === $keys) {
            if (self::REMOVE === $value) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }

            return $data;
        }
        $data[$key] = self::edit($data[$key] ?? null, implode('.', $keys), $value);

        return $data;
    }
}
