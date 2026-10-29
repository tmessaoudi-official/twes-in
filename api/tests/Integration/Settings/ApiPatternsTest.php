<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Settings;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Settings\Application\SettingCatalog;
use App\Settings\Domain\ValueObject\SettingChain;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every pattern the API hands a screen, which the screen checks a value against before the API does: each setting's
 * (written as PHP writes one, read by the web's `browserPattern`), and each fiscal preset's identifier and establishment
 * code patterns (written without delimiters). A screen reading one otherwise than the API refuses what the API takes,
 * as six platform fields once refused every value. So each one is named in web/src/app/shared/form/api-patterns.json
 * with values the API takes and values it refuses, which `api-pattern.spec.ts` runs through the screens' own reading.
 */
final class ApiPatternsTest extends KernelTestCase
{
    private const string VECTORS = __DIR__.'/../../../../web/src/app/shared/form/api-patterns.json';
    /** What each holder a form asks an identifier of is called here, the vendor's being the preset's own name ''. */
    private const array HOLDERS = [IdentifierRules::COMPANY, IdentifierRules::BUSINESS_CUSTOMER, ''];

    public function testEveryPatternSentToAScreenHasItsVectorsAndNoVectorIsLeftOver(): void
    {
        $vectors = self::vectors();
        $sent = self::sent();

        self::assertSame([], array_values(array_diff(array_keys($sent), array_keys($vectors))), 'a pattern the API sends a screen with no values in api-patterns.json');
        self::assertSame([], array_values(array_diff(array_keys($vectors), array_keys($sent))), 'values in api-patterns.json for a pattern the API no longer sends');
        foreach ($sent as $pattern => $delimited) {
            self::assertSame($delimited, $vectors[$pattern]['delimited'], "$pattern is sent ".($delimited ? 'with' : 'without').' its delimiters');
        }
        self::assertGreaterThanOrEqual(12, \count($sent), 'fewer patterns found than the API sent when this test was written: the senders moved');
    }

    public function testTheApiTakesEachValidValueAndRefusesEachInvalidOne(): void
    {
        $vectors = self::vectors();
        self::assertNotSame([], $vectors);
        foreach ($vectors as $pattern => $vector) {
            self::assertNotSame([], $vector['valid'], "$pattern has no value it takes");
            self::assertNotSame([], $vector['invalid'], "$pattern has no value it refuses");
            $rule = $vector['delimited'] ? $pattern : '#'.str_replace('#', '\#', $pattern).'#u';
            foreach ($vector['valid'] as $value) {
                self::assertSame(1, preg_match($rule, $value), "$pattern takes « $value »");
            }
            foreach ($vector['invalid'] as $value) {
                self::assertSame(0, preg_match($rule, $value), "$pattern refuses « $value »");
            }
        }
    }

    /** @return array<string, bool> every pattern sent, whether it is written with its delimiters */
    private static function sent(): array
    {
        $sent = [];
        $catalog = static::getContainer()->get(SettingCatalog::class);
        foreach (SettingChain::cases() as $chain) {
            foreach ($catalog->ofChain($chain) as $definition) {
                if (null !== $definition->pattern) {
                    $sent[$definition->pattern] = true;
                }
            }
        }
        $presets = static::getContainer()->get(FiscalPresets::class);
        foreach ($presets->keys() as $key) {
            $preset = $presets->get($key);
            foreach ($preset->identifiers as $identifier) {
                foreach (self::HOLDERS as $holder) {
                    $pattern = $identifier->patternFor($holder);
                    if (null !== $pattern) {
                        $sent[$pattern] = false;
                    }
                }
            }
            $sent[$preset->establishment->codePattern] = false;
        }
        ksort($sent);

        return $sent;
    }

    /** @return array<string, array{delimited: bool, valid: list<string>, invalid: list<string>}> */
    private static function vectors(): array
    {
        /** @var array<string, array{delimited: bool, valid: list<string>, invalid: list<string>}> $vectors */
        $vectors = json_decode((string) file_get_contents(self::VECTORS), true, 512, \JSON_THROW_ON_ERROR);

        return $vectors;
    }
}
