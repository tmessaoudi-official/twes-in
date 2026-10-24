<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Texts\Domain;

use App\Shared\Domain\Service\TypedText;
use App\Texts\Domain\Service\OverrideRules;
use App\Texts\Domain\ValueObject\TextRefusal;
use App\Texts\Domain\ValueObject\TextSyntax;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * What a changed text may say in place of the one shipped: anything, as long as everything the code fills in or
 * chooses from is still there. A placeholder lost would print nothing where an amount was; a plural choice broken
 * would print its braces.
 */
final class OverrideRulesTest extends TestCase
{
    /** @return iterable<string, array{TextSyntax, string, string, ?TextRefusal}> */
    public static function cases(): iterable
    {
        yield 'a word changed' => [TextSyntax::Screen, 'Valider et numéroter', 'Émettre', null];
        yield 'its placeholders kept, moved' => [TextSyntax::Screen, 'Facture {{number}} du {{date}}', 'Le {{date}}, facture {{number}}', null];
        yield 'spaces inside the braces' => [TextSyntax::Screen, 'Reste {{ amount }}', 'Il reste {{amount}}', null];
        yield 'a placeholder lost' => [TextSyntax::Screen, 'Facture {{number}}', 'Facture', TextRefusal::Placeholders];
        yield 'a placeholder renamed' => [TextSyntax::Screen, 'Facture {{number}}', 'Facture {{numero}}', TextRefusal::Placeholders];
        yield 'a placeholder added' => [TextSyntax::Screen, 'Facture', 'Facture {{number}}', TextRefusal::Placeholders];
        yield 'empty' => [TextSyntax::Screen, 'Facture', '', TextRefusal::Empty];
        yield 'blank' => [TextSyntax::Screen, 'Facture', "  \u{00A0} ", TextRefusal::Empty];
        yield 'the API\'s placeholders kept' => [TextSyntax::Api, 'Acompte sur le devis %number%', 'Acompte, devis %number%', null];
        yield 'the API\'s placeholder lost' => [TextSyntax::Api, 'Acompte sur le devis %number%', 'Acompte', TextRefusal::Placeholders];
        yield 'braces are plain text to the API' => [TextSyntax::Api, 'Total', 'Total {{x}}', null];
        yield 'a plural reworded' => [
            TextSyntax::Screen,
            '{count, plural, one {membre} other {membres}}',
            '{count, plural, one {personne} other {personnes}}',
            null,
        ];
        yield 'a plural given Arabic\'s forms' => [
            TextSyntax::Screen,
            '{count, plural, one {membre} other {membres}}',
            '{count, plural, zero {x} one {x} two {x} few {x} many {x} other {x}}',
            null,
        ];
        yield 'an exact count' => [TextSyntax::Screen, '{count, plural, one {un} other {plusieurs}}', '{count, plural, =0 {aucun} other {quelques-uns}}', null];
        yield 'a plural removed' => [TextSyntax::Screen, '{{count}} {count, plural, one {membre} other {membres}}', '{{count}} membres', TextRefusal::Plural];
        yield 'a plural on another count' => [TextSyntax::Screen, '{count, plural, one {a} other {b}}', '{days, plural, one {a} other {b}}', TextRefusal::Plural];
        yield 'a plural without other' => [TextSyntax::Screen, '{count, plural, one {a} other {b}}', '{count, plural, one {a}}', TextRefusal::Plural];
        yield 'a form no language has' => [TextSyntax::Screen, '{count, plural, one {a} other {b}}', '{count, plural, several {a} other {b}}', TextRefusal::Plural];
        yield 'a brace left open' => [TextSyntax::Screen, '{count, plural, one {a} other {b}}', '{count, plural, one {a} other {b}', TextRefusal::Plural];
        yield 'a placeholder inside a form' => [
            TextSyntax::Screen,
            '{count, plural, one {{{count}} jour} other {{{count}} jours}}',
            '{count, plural, one {un jour} other {{{count}} jours}}',
            null,
        ];
    }

    #[DataProvider('cases')]
    public function testAChangedTextKeepsWhatTheCodeFillsIn(TextSyntax $syntax, string $original, string $value, ?TextRefusal $expected): void
    {
        self::assertSame($expected, new OverrideRules(2000)->refusal($syntax, $original, $value));
    }

    public function testEveryShippedTextPassesAsItsOwnOverride(): void
    {
        $rules = new OverrideRules(\PHP_INT_MAX);
        $checked = 0;
        $root = \dirname(__DIR__, 5);
        foreach ([...glob($root.'/web/public/i18n/*.json') ?: [], ...glob($root.'/web/public/i18n/*/*.json') ?: []] as $file) {
            foreach (self::leaves(json_decode((string) file_get_contents($file), true, flags: \JSON_THROW_ON_ERROR)) as $key => $text) {
                self::assertNull($rules->refusal(TextSyntax::Screen, $text, $text), $file.': '.$key);
                ++$checked;
            }
        }
        foreach (glob($root.'/api/translations/*.yaml') ?: [] as $file) {
            foreach (self::leaves(Yaml::parseFile($file)) as $key => $text) {
                self::assertNull($rules->refusal(TextSyntax::Api, $text, $text), $file.': '.$key);
                ++$checked;
            }
        }
        self::assertGreaterThan(15000, $checked, 'every language\'s screen texts and the API\'s were read');
    }

    /**
     * @return iterable<string, string> each text by its dotted key, but a separator that is a space itself (format.*)
     */
    private static function leaves(mixed $node, string $prefix = ''): iterable
    {
        if (\is_string($node)) {
            if ('' !== TypedText::trimmed($node)) {
                yield $prefix => $node;
            }

            return;
        }
        if (\is_array($node)) {
            foreach ($node as $key => $child) {
                yield from self::leaves($child, '' === $prefix ? (string) $key : $prefix.'.'.$key);
            }
        }
    }

    public function testATextPastTheLengthAllowedIsRefused(): void
    {
        $rules = new OverrideRules(10);
        self::assertNull($rules->refusal(TextSyntax::Screen, 'Facture', str_repeat('é', 10)));
        self::assertSame(TextRefusal::TooLong, $rules->refusal(TextSyntax::Screen, 'Facture', str_repeat('é', 11)));
    }
}
