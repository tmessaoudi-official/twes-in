<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Preset;

use App\Fiscal\Application\Preset\FiscalPreset;
use App\Fiscal\Application\Preset\PresetEstablishment;
use App\Fiscal\Application\Preset\PresetIdentifier;
use App\Fiscal\Application\Preset\PresetNumbering;
use App\Fiscal\Application\Preset\PresetRegime;
use App\Fiscal\Application\Preset\PresetTaxComponent;
use App\Fiscal\Application\Preset\PresetUnit;
use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\IdentifierCheck;
use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\TaxKind;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\InvalidNumbering;
use App\Tenancy\Domain\NumberFormat;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;

/**
 * Turns a structurally valid preset (FiscalPresetConfiguration) into a FiscalPreset, refusing what depends on
 * several values at once. Each refusal names the file, the path and the rule.
 */
final readonly class PresetReader
{
    /** The languages the product renders documents in (api/translations). */
    private const array LANGUAGES = ['fr', 'en'];
    /** A percentage that fits the NUMERIC(6,3) rate columns. */
    private const string RATE = '/^(0|[1-9][0-9]{0,2})(\.[0-9]{1,3})?$/';
    private const string AMOUNT = '/^(0|[1-9][0-9]{0,10})(?:\.([0-9]+))?$/';
    private const string CODE = '/^[A-Z][A-Z0-9_]{0,31}$/';
    private const string UNIT_CODE = '/^[A-Z0-9]{2,3}$/';
    private const string REGIME_CODE = '/^[a-z][a-z0-9_]{0,23}$/';
    /** The shape of a CEF VATEX code: VATEX-EU-IC, VATEX-FR-FRANCHISE, VATEX-EU-132-1A. */
    private const string VATEX_CODE = '/^VATEX-[A-Z]{2}-[A-Z0-9]+(-[A-Z0-9]+)*$/';
    private const string TRANSLATION_KEY = '/^fiscal(\.[a-z0-9_]+){2,}$/';

    public function __construct(private string $file, private string $key)
    {
    }

    /** @param array<mixed> $config */
    public function read(array $config): FiscalPreset
    {
        $country = $this->string($config, 'country', 'country');
        if ($country !== $this->key || !Countries::exists($country)) {
            $this->refuse('country', "must be the country the file is named for ({$this->key})");
        }
        $currency = $this->string($config, 'currency', 'currency');
        if (!Currencies::exists($currency)) {
            $this->refuse('currency', "\"$currency\" is not an ISO 4217 currency");
        }
        $minorUnit = $this->int($config, 'minor_unit', 'minor_unit');
        if (Currencies::getFractionDigits($currency) !== $minorUnit) {
            $this->refuse('minor_unit', "$currency has ".Currencies::getFractionDigits($currency)." decimals, not $minorUnit");
        }

        $languages = $this->strings($config, 'document_languages', 'document_languages');
        if ([] !== array_diff($languages, self::LANGUAGES)) {
            $this->refuse('document_languages', 'may only name '.implode(', ', self::LANGUAGES));
        }

        $rounding = $this->node($config, 'rounding', 'rounding');

        return new FiscalPreset(
            $country,
            $currency,
            $minorUnit,
            $languages,
            RoundingPoint::from($this->string($rounding, 'vat_point', 'rounding.vat_point')),
            TaxBasis::from($this->string($rounding, 'tax_basis', 'rounding.tax_basis')),
            $this->identifiers($this->node($config, 'identifiers', 'identifiers')),
            $this->components($this->node($config, 'tax_components', 'tax_components'), $minorUnit),
            $this->regimes($this->node($config, 'customer_tax_regimes', 'customer_tax_regimes'), 'customer_tax_regimes'),
            $this->regimes($this->node($config, 'company_vat_regimes', 'company_vat_regimes'), 'company_vat_regimes'),
            $this->mentions($config),
            $this->numbering($this->node($config, 'numbering', 'numbering')),
            $this->units($this->node($config, 'units', 'units')),
            $this->establishment($this->node($config, 'establishment', 'establishment')),
        );
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return list<PresetIdentifier>
     */
    private function identifiers(array $nodes): array
    {
        $identifiers = [];
        foreach (array_values($nodes) as $i => $node) {
            $path = "identifiers.$i";
            $node = $this->asNode($node, $path);
            $pattern = $this->string($node, 'pattern', "$path.pattern");
            if (false === @preg_match("\x01".$pattern."\x01u", '')) {
                $this->refuse("$path.pattern", 'is not a valid regular expression');
            }
            $identifiers[] = new PresetIdentifier(
                $this->string($node, 'key', "$path.key"),
                $this->translationKey($node, 'label_key', "$path.label_key"),
                $pattern,
                $this->strings($node, 'required_for', "$path.required_for"),
                \is_string($node['check'] ?? null) ? IdentifierCheck::from($node['check']) : null,
            );
        }

        return $identifiers;
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return list<PresetTaxComponent>
     */
    private function components(array $nodes, int $minorUnit): array
    {
        $components = [];
        $seen = [];
        foreach (array_values($nodes) as $i => $node) {
            $path = "tax_components.$i";
            $node = $this->asNode($node, $path);
            $code = $this->string($node, 'code', "$path.code");
            if (1 !== preg_match(self::CODE, $code)) {
                $this->refuse("$path.code", "\"$code\" is not a code (capital letters, digits and underscores)");
            }
            if (isset($seen[$code])) {
                $this->refuse("$path.code", "$code is already used by another component");
            }
            $seen[$code] = true;

            $kind = TaxKind::from($this->string($node, 'kind', "$path.kind"));
            $family = TaxFamily::from($this->string($node, 'family', "$path.family"));
            if ($family->kind() !== $kind) {
                $this->refuse("$path.family", "a {$family->value} tax is a {$family->kind()->value}, not a {$kind->value}");
            }
            $entersVatBase = $this->bool($node, 'enters_vat_base', "$path.enters_vat_base");
            if ($entersVatBase && !$family->mayEnterVatBase()) {
                $this->refuse("$path.enters_vat_base", "only a levy enters the VAT base, not a {$family->value} tax");
            }

            $rate = $this->decimalOrNull($node, 'rate', "$path.rate", self::RATE);
            $amount = $this->decimalOrNull($node, 'amount', "$path.amount", self::AMOUNT, $minorUnit);
            $threshold = $this->decimalOrNull($node, 'threshold', "$path.threshold", self::AMOUNT, $minorUnit);
            $this->requirePresence($path, 'rate', $rate, TaxKind::FixedDocument !== $kind, $kind);
            $this->requirePresence($path, 'amount', $amount, TaxKind::FixedDocument === $kind, $kind);
            $this->requirePresence($path, 'threshold', $threshold, TaxKind::WithholdingTotal === $kind, $kind);

            $components[] = new PresetTaxComponent(
                $code,
                $this->names($node, "$path.names"),
                $kind,
                $family,
                $rate,
                $amount,
                $threshold,
                $entersVatBase,
                $this->bool($node, 'is_default', "$path.is_default"),
                ($i + 1) * 10,
            );
        }

        return $components;
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return list<PresetRegime>
     */
    private function regimes(array $nodes, string $section): array
    {
        $regimes = [];
        $seen = [];
        foreach (array_values($nodes) as $i => $node) {
            $path = "$section.$i";
            $node = $this->asNode($node, $path);
            $code = $this->string($node, 'code', "$path.code");
            if (1 !== preg_match(self::REGIME_CODE, $code)) {
                $this->refuse("$path.code", "\"$code\" is not a regime code (lower-case letters, digits and underscores)");
            }
            if (isset($seen[$code])) {
                $this->refuse("$path.code", "$code is already used by another regime");
            }
            $seen[$code] = true;

            $families = [];
            foreach ($this->strings($node, 'excluded_families', "$path.excluded_families") as $family) {
                $families[] = TaxFamily::tryFrom($family) ?? $this->refuse("$path.excluded_families", "\"$family\" is not a tax family");
            }
            $mentionKey = null === ($node['mention_key'] ?? null) ? null : $this->translationKey($node, 'mention_key', "$path.mention_key");

            [$vatCategory, $vatExemptionCode] = $this->vatExemption($node, $path);
            if (null !== $vatCategory && !\in_array(TaxFamily::Vat, $families, true)) {
                $this->refuse("$path.vat_category", 'belongs to a regime that excludes the vat family');
            }

            $regimes[] = new PresetRegime($code, $this->translationKey($node, 'label_key', "$path.label_key"), $families, $mentionKey, ($i + 1) * 10, $vatCategory, $vatExemptionCode);
        }

        return $regimes;
    }

    /**
     * A regime's EN 16931 VAT category and VATEX code, both or neither: a category under which no VAT is charged, and a
     * code from the CEF VATEX list's shape (the list itself is checked by the validator that receives the invoice).
     *
     * @param array<mixed> $node
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function vatExemption(array $node, string $path): array
    {
        $category = null === ($node['vat_category'] ?? null) ? null : $this->string($node, 'vat_category', "$path.vat_category");
        $code = null === ($node['vat_exemption_code'] ?? null) ? null : $this->string($node, 'vat_exemption_code', "$path.vat_exemption_code");
        if (null === $category && null !== $code) {
            $this->refuse("$path.vat_category", 'must be declared beside vat_exemption_code');
        }
        if (null !== $category && null === $code) {
            $this->refuse("$path.vat_exemption_code", 'must be declared beside vat_category');
        }
        if (null !== $category && !\in_array($category, PresetRegime::VAT_CATEGORIES_WITHOUT_VAT, true)) {
            $this->refuse("$path.vat_category", \sprintf('"%s" is not a VAT category under which no VAT is charged (%s)', $category, implode(', ', PresetRegime::VAT_CATEGORIES_WITHOUT_VAT)));
        }
        if (null !== $code && 1 !== preg_match(self::VATEX_CODE, $code)) {
            $this->refuse("$path.vat_exemption_code", \sprintf('"%s" is not a VATEX code (VATEX-, a two-letter scope, then capitals, digits and hyphens)', $code));
        }

        return [$category, $code];
    }

    /**
     * @param array<mixed> $config
     *
     * @return list<string>
     */
    private function mentions(array $config): array
    {
        $mentions = \is_array($config['mentions'] ?? null) ? $config['mentions'] : [];
        $keys = [];
        foreach ($this->strings($mentions + ['invoice' => []], 'invoice', 'mentions.invoice') as $i => $key) {
            if (1 !== preg_match(self::TRANSLATION_KEY, $key)) {
                $this->refuse("mentions.invoice.$i", "\"$key\" is not a key under fiscal.");
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return array<string, PresetNumbering>
     */
    private function numbering(array $nodes): array
    {
        $numbering = [];
        foreach ($nodes as $type => $node) {
            $path = "numbering.$type";
            $node = $this->asNode($node, $path);
            $format = $this->string($node, 'format', "$path.format");
            try {
                new NumberFormat($format);
            } catch (InvalidNumbering $refused) {
                $this->refuse("$path.format", $refused->getMessage());
            }
            $numbering[(string) $type] = new PresetNumbering($format, $this->string($node, 'reset', "$path.reset"));
        }

        return $numbering;
    }

    /** @param array<mixed> $node */
    private function establishment(array $node): PresetEstablishment
    {
        $pattern = $this->string($node, 'code_pattern', 'establishment.code_pattern');
        if (false === @preg_match("\x01".$pattern."\x01u", '')) {
            $this->refuse('establishment.code_pattern', 'is not a valid regular expression');
        }
        $default = $this->string($node, 'default_code', 'establishment.default_code');
        if (1 !== preg_match("\x01".$pattern."\x01u", $default) || 1 !== preg_match(Establishment::CODE, $default)) {
            $this->refuse('establishment.default_code', "\"$default\" matches neither the code pattern nor the shape of an establishment code");
        }

        return new PresetEstablishment($default, $pattern);
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return list<PresetUnit>
     */
    private function units(array $nodes): array
    {
        $units = [];
        $seen = [];
        foreach (array_values($nodes) as $i => $node) {
            $path = "units.$i";
            $node = $this->asNode($node, $path);
            $code = $this->string($node, 'code', "$path.code");
            if (1 !== preg_match(self::UNIT_CODE, $code)) {
                $this->refuse("$path.code", "\"$code\" is not a UN/ECE Recommendation 20 code");
            }
            if (isset($seen[$code])) {
                $this->refuse("$path.code", "$code is already used by another unit");
            }
            $seen[$code] = true;
            $units[] = new PresetUnit($code, $this->names($node, "$path.names"), $this->int($node, 'decimals', "$path.decimals"), ($i + 1) * 10);
        }

        return $units;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<string, string>
     */
    private function names(array $node, string $path): array
    {
        $names = $this->node($node, 'names', $path);
        $out = [];
        foreach (self::LANGUAGES as $language) {
            $out[$language] = $this->string($names, $language, "$path.$language");
        }

        return $out;
    }

    private function requirePresence(string $path, string $field, ?string $value, bool $required, TaxKind $kind): void
    {
        if ($required && null === $value) {
            $this->refuse("$path.$field", "a {$kind->value} tax needs a $field");
        }
        if (!$required && null !== $value) {
            $this->refuse("$path.$field", "a {$kind->value} tax has no $field");
        }
    }

    /** @param array<mixed> $node */
    private function decimalOrNull(array $node, string $key, string $path, string $shape, ?int $maxDecimals = null): ?string
    {
        $value = $node[$key] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || 1 !== preg_match($shape, $value, $match)) {
            $this->refuse($path, 'must be a quoted decimal that fits its column');
        }
        if (null !== $maxDecimals && \strlen($match[2] ?? '') > $maxDecimals) {
            $this->refuse($path, "may carry at most $maxDecimals decimals, the currency's");
        }

        return $value;
    }

    /** @param array<mixed> $node */
    private function translationKey(array $node, string $key, string $path): string
    {
        $value = $this->string($node, $key, $path);
        if (1 !== preg_match(self::TRANSLATION_KEY, $value)) {
            $this->refuse($path, "\"$value\" is not a key under fiscal.");
        }

        return $value;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private function node(array $node, string $key, string $path): array
    {
        return $this->asNode($node[$key] ?? null, $path);
    }

    /** @return array<mixed> */
    private function asNode(mixed $value, string $path): array
    {
        return \is_array($value) ? $value : $this->refuse($path, 'must be a mapping or a list');
    }

    /** @param array<mixed> $node */
    private function string(array $node, string $key, string $path): string
    {
        $value = $node[$key] ?? null;

        return \is_string($value) && '' !== $value ? $value : $this->refuse($path, 'must be a non-empty string');
    }

    /** @param array<mixed> $node */
    private function int(array $node, string $key, string $path): int
    {
        $value = $node[$key] ?? null;

        return \is_int($value) ? $value : $this->refuse($path, 'must be an integer');
    }

    /** @param array<mixed> $node */
    private function bool(array $node, string $key, string $path): bool
    {
        $value = $node[$key] ?? null;

        return \is_bool($value) ? $value : $this->refuse($path, 'must be true or false');
    }

    /**
     * @param array<mixed> $node
     *
     * @return list<string>
     */
    private function strings(array $node, string $key, string $path): array
    {
        $out = [];
        foreach ($this->node($node, $key, $path) as $i => $value) {
            $out[] = \is_string($value) ? $value : $this->refuse("$path.$i", 'must be a string');
        }

        return $out;
    }

    private function refuse(string $path, string $rule): never
    {
        throw new InvalidFiscalPreset("{$this->file}: $path $rule.");
    }
}
