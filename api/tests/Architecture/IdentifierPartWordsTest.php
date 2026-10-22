<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Fiscal\Infrastructure\Preset\YamlFiscalPresets;
use App\Tests\Support\ShippedLanguages;
use PHPUnit\Framework\TestCase;

/**
 * A refusal names a registration number's part and the rule between two parts by their preset names, and the screens
 * word them (`import.identifier_parts`, `import.identifier_rules`, `shared/form/refusal-words.ts`). A part a preset adds
 * without its words would be said to a person as « vat_code »: nothing on either side alone sees the pair.
 */
final class IdentifierPartWordsTest extends TestCase
{
    private const string SHIPPED = __DIR__.'/../../config/fiscal';
    private const string TEXTS = __DIR__.'/../../../web/public/i18n';

    public function testEveryPartAndRuleAPresetNamesIsWordedInEveryLanguageTheScreensSpeak(): void
    {
        $presets = new YamlFiscalPresets(self::SHIPPED, ShippedLanguages::catalogue());
        $named = ['identifier_parts' => [], 'identifier_rules' => []];
        foreach (['TN', 'FR'] as $country) {
            foreach ($presets->get($country)->identifiers as $identifier) {
                foreach ($identifier->parts->parts ?? [] as $part) {
                    $named['identifier_parts'][] = "$country $identifier->key: $part->name";
                }
                foreach ($identifier->parts->rules ?? [] as $rule) {
                    $named['identifier_rules'][] = "$country $identifier->key: $rule->name";
                }
            }
        }
        self::assertGreaterThanOrEqual(5, \count($named['identifier_parts']), 'the matricule fiscal is read by its five parts');
        self::assertNotEmpty($named['identifier_rules']);

        $missing = [];
        foreach (['fr', 'en', 'ar'] as $language) {
            $texts = json_decode((string) file_get_contents(self::TEXTS."/$language.json"), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($texts);
            foreach ($named as $section => $names) {
                foreach ($names as $where) {
                    $name = substr($where, (int) strrpos($where, ' ') + 1);
                    $said = \is_array($texts['import'] ?? null) && \is_array($texts['import'][$section] ?? null) ? $texts['import'][$section][$name] ?? null : null;
                    if (!\is_string($said) || '' === $said) {
                        $missing[] = "$where: no import.$section.$name in $language.json";
                    }
                }
            }
        }

        self::assertSame([], $missing);
    }
}
