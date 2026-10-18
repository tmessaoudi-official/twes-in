<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Fiscal\Infrastructure;

use App\Fiscal\Application\Preset\FiscalPreset;
use App\Fiscal\Infrastructure\Preset\YamlFiscalPresets;
use App\Module\Invoices\Application\Print\InvoiceMentions;
use App\Tests\Support\ShippedLanguages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A preset names its labels and printed mentions by translation key, and the API renders them in French and
 * English: every key a preset uses exists in both files, and the two files carry the same keys.
 */
final class FiscalTranslationsTest extends TestCase
{
    private const string PRESETS = __DIR__.'/../../../../config/fiscal';
    private const string TRANSLATIONS = __DIR__.'/../../../../translations';
    private const string SCREEN_TEXTS = __DIR__.'/../../../../../web/public/i18n';

    public function testFrenchAndEnglishCarryTheSameKeys(): void
    {
        self::assertSame(array_keys($this->messages('fr')), array_keys($this->messages('en')));
    }

    public function testEveryKeyAPresetUsesIsTranslated(): void
    {
        $presets = new YamlFiscalPresets(self::PRESETS, ShippedLanguages::catalogue());
        $used = [];
        foreach ($presets->keys() as $key) {
            $used = [...$used, ...$this->keysOf($presets->get($key))];
        }
        self::assertNotEmpty($used);

        foreach (ShippedLanguages::catalogue()->documents() as $locale) {
            $missing = array_values(array_diff(array_unique($used), array_keys($this->messages($locale))));
            self::assertSame([], $missing, "fiscal.$locale.yaml lacks a key a preset uses");
        }
    }

    /**
     * Every kind of document a preset numbers has a series on the numbering page, named there in the screen's words:
     * a kind the screen texts do not name shows its raw key.
     */
    public function testEveryDocumentTypeAPresetNumbersIsNamedOnTheScreen(): void
    {
        $presets = new YamlFiscalPresets(self::PRESETS, ShippedLanguages::catalogue());
        $numbered = [];
        foreach ($presets->keys() as $key) {
            $numbered = [...$numbered, ...array_keys($presets->get($key)->numbering)];
        }
        self::assertContains('purchase_order', $numbered, 'the presets are read, so an empty set cannot pass');

        foreach (ShippedLanguages::catalogue()->interface() as $language) {
            $texts = json_decode((string) file_get_contents(self::SCREEN_TEXTS."/$language.json"), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($texts);
            $named = $texts;
            foreach (['company', 'numbering', 'document_types'] as $step) {
                self::assertIsArray($named[$step] ?? null, "$language.json has company.numbering.document_types");
                $named = $named[$step];
            }
            self::assertSame([], array_values(array_diff(array_unique($numbered), array_keys($named))), "$language.json names every kind of document a preset numbers");
        }
    }

    /**
     * A mention's placeholders are what issuing fills from the company's settings: each language waits for the same
     * ones, and each is one a setting fills, or every document printing it would be refused.
     */
    public function testEveryPlaceholderAMentionHoldsIsOneASettingFillsInBothLanguages(): void
    {
        $holding = 0;
        foreach (array_keys($this->messages('fr')) as $key) {
            if (!str_starts_with($key, 'fiscal.mention.')) {
                continue;
            }
            $placeholders = array_map(fn (string $locale): array => $this->placeholders($this->messages($locale)[$key]), ShippedLanguages::catalogue()->documents());
            foreach ($placeholders as $inOne) {
                self::assertSame($placeholders[0], $inOne, "$key waits for the same in every language");
            }
            self::assertSame([], array_values(array_diff($placeholders[0], array_keys(InvoiceMentions::DATA))), "$key waits for something no setting fills");
            $holding += [] === $placeholders[0] ? 0 : 1;
        }
        self::assertGreaterThanOrEqual(2, $holding, 'the French late payment and exemption mentions hold placeholders');
    }

    /** @return list<string> */
    private function placeholders(string $message): array
    {
        preg_match_all('/%([a-z_]+)%/', $message, $found);
        $names = array_values(array_unique($found[1]));
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function keysOf(FiscalPreset $preset): array
    {
        $keys = $preset->invoiceMentions;
        foreach ($preset->identifiers as $identifier) {
            $keys[] = $identifier->labelKey;
        }
        foreach ([...$preset->customerTaxRegimes, ...$preset->companyVatRegimes] as $regime) {
            $keys[] = $regime->labelKey;
            if (null !== $regime->mentionKey) {
                $keys[] = $regime->mentionKey;
            }
        }

        return $keys;
    }

    /** @return array<string, string> the file's messages under their dotted keys, sorted */
    private function messages(string $locale): array
    {
        $flat = [];
        $walk = static function (mixed $node, string $prefix) use (&$walk, &$flat): void {
            if (\is_array($node)) {
                foreach ($node as $key => $child) {
                    $walk($child, '' === $prefix ? (string) $key : "$prefix.$key");
                }

                return;
            }
            self::assertIsString($node, "$prefix is a message");
            $flat[$prefix] = $node;
        };
        $walk(Yaml::parseFile(self::TRANSLATIONS."/fiscal.$locale.yaml"), '');
        ksort($flat);

        return $flat;
    }
}
