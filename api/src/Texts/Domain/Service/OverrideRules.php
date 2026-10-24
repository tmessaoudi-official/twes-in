<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Domain\Service;

use App\Shared\Domain\Service\TypedText;
use App\Texts\Domain\ValueObject\TextRefusal;
use App\Texts\Domain\ValueObject\TextSyntax;

/**
 * What a changed text may say in place of the one shipped: any words, as long as everything the code fills in or
 * chooses from is still there. The plural choices are compared by the count they are made on, not by their forms,
 * which each language names its own way (French one and other, Arabic six).
 */
final readonly class OverrideRules
{
    /** A parameter, as ngx-translate's default parser reads it, and as the web's PluralCompiler does. */
    private const string SCREEN_PARAMETER = '/{{\s?([^{}\s]*)\s?}}/';
    private const string API_PARAMETER = '/%([A-Za-z_][A-Za-z0-9_]*)%/';
    /** The forms CLDR names, and an exact count. */
    private const string FORM = '/^(zero|one|two|few|many|other|=\d+)$/';

    public function __construct(private int $maxLength)
    {
    }

    public function refusal(TextSyntax $syntax, string $original, string $value): ?TextRefusal
    {
        if ('' === TypedText::trimmed($value)) {
            return TextRefusal::Empty;
        }
        if (mb_strlen($value) > $this->maxLength) {
            return TextRefusal::TooLong;
        }
        if (TextSyntax::Screen === $syntax) {
            $choices = self::choices($value);
            if (null === $choices || self::countsOf($choices) !== self::countsOf(self::choices($original) ?? [])) {
                return TextRefusal::Plural;
            }
        }
        $parameter = TextSyntax::Screen === $syntax ? self::SCREEN_PARAMETER : self::API_PARAMETER;

        return self::names($parameter, $original) === self::names($parameter, $value) ? null : TextRefusal::Placeholders;
    }

    /** @return list<string> sorted, once each */
    private static function names(string $pattern, string $text): array
    {
        preg_match_all($pattern, $text, $found);
        $names = array_values(array_unique($found[1]));
        sort($names);

        return $names;
    }

    /**
     * @param list<string> $choices
     *
     * @return list<string> sorted
     */
    private static function countsOf(array $choices): array
    {
        sort($choices);

        return $choices;
    }

    /**
     * The count every plural choice of a text is made on, nested ones included, or null when a choice is malformed: a
     * brace left open, a form no language has, or no « other ».
     *
     * @return list<string>|null
     */
    private static function choices(string $text): ?array
    {
        $counts = [];
        $offset = 0;
        while (1 === preg_match('/\{(\w+),\s*plural,/', $text, $start, \PREG_OFFSET_CAPTURE, $offset)) {
            $counts[] = $start[1][0];
            $at = $start[0][1] + \strlen($start[0][0]);
            $forms = [];
            while (true) {
                if (1 !== preg_match('/\G\s*(\}|([^\s{}]+)\s*\{)/', $text, $form, 0, $at)) {
                    return null;
                }
                $at += \strlen($form[0]);
                $name = $form[2] ?? null;
                if (null === $name) {
                    break;
                }
                if (1 !== preg_match(self::FORM, $name)) {
                    return null;
                }
                $forms[] = $name;
                $close = self::closing($text, $at);
                if (null === $close) {
                    return null;
                }
                $nested = self::choices(substr($text, $at, $close - $at));
                if (null === $nested) {
                    return null;
                }
                array_push($counts, ...$nested);
                $at = $close + 1;
            }
            if (!\in_array('other', $forms, true)) {
                return null;
            }
            $offset = $at;
        }

        return $counts;
    }

    /** Where the brace opened just before $at closes, or null. */
    private static function closing(string $text, int $at): ?int
    {
        $depth = 1;
        for ($i = $at, $length = \strlen($text); $i < $length; ++$i) {
            if ('{' === $text[$i]) {
                ++$depth;
            } elseif ('}' === $text[$i] && 0 === --$depth) {
                return $i;
            }
        }

        return null;
    }
}
