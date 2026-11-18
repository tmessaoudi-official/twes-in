<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application;

/**
 * A screen text filled in as the screen fills it: each `{{name}}` by its value (ngx-translate's parameter, kept as
 * written when no value is given), each `{name, plural, one {…} other {…}}` by the form its language gives the count
 * (the web's PluralCompiler: `=N` first, then the form, then « other »; a malformed choice kept as text). The plural
 * form is CLDR's, read through ICU as the browser's Intl.PluralRules reads it. The web's vectors hold the two equal
 * (`screen-text-vectors.json`).
 */
final class ScreenMessage
{
    private const string CHOICE = '/\{(\w+),\s*plural,/';
    private const string PARAMETER = '/{{\s?([^{}\s]*)\s?}}/';
    private const string FORM = '/^(=\d+|zero|one|two|few|many|other)\s*\{/';
    private const string CATEGORY = '{n, plural, zero {zero} one {one} two {two} few {few} many {many} other {other}}';

    /** @param array<mixed> $params the values by name; only whole counts are read, as a report column's are */
    public static function say(string $text, string $language, array $params = []): string
    {
        return self::spoken(self::parse($text), $language, array_filter($params, \is_int(...)));
    }

    /**
     * @param list<string|array{name: string, forms: array<string, list<mixed>>}> $parts
     * @param array<int>                                                          $params
     */
    private static function spoken(array $parts, string $language, array $params): string
    {
        $said = '';
        foreach ($parts as $part) {
            if (\is_string($part)) {
                $said .= (string) preg_replace_callback(self::PARAMETER, static fn (array $found): string => isset($params[$found[1]]) ? (string) $params[$found[1]] : $found[0], $part);
                continue;
            }
            $count = $params[$part['name']] ?? null;
            $form = null === $count ? 'other' : self::category($count, $language);
            /** @var list<string|array{name: string, forms: array<string, list<mixed>>}> $chosen */
            $chosen = (null === $count ? null : $part['forms']['='.$count] ?? null) ?? $part['forms'][$form] ?? $part['forms']['other'] ?? [];
            $said .= self::spoken($chosen, $language, $params);
        }

        return $said;
    }

    private static function category(int $count, string $language): string
    {
        $category = \MessageFormatter::formatMessage($language, self::CATEGORY, ['n' => $count]);

        return false === $category ? 'other' : $category;
    }

    /** @return list<string|array{name: string, forms: array<string, list<mixed>>}> its plain runs and its choices */
    private static function parse(string $text): array
    {
        $parts = [];
        $from = 0;
        $at = 0;
        while (1 === preg_match(self::CHOICE, $text, $found, \PREG_OFFSET_CAPTURE, $at)) {
            $start = $found[0][1];
            $read = self::forms($text, $start + \strlen($found[0][0]));
            $at = $start + 1;
            if (null === $read) {
                continue;
            }
            if ($start > $from) {
                $parts[] = substr($text, $from, $start - $from);
            }
            $parts[] = ['name' => $found[1][0], 'forms' => $read['forms']];
            $from = $at = $read['end'];
        }
        if ($from < \strlen($text)) {
            $parts[] = substr($text, $from);
        }

        return $parts;
    }

    /** @return array{forms: array<string, list<mixed>>, end: int}|null `one {…} other {…}}` read from `$at`, its closing brace included */
    private static function forms(string $text, int $at): ?array
    {
        $forms = [];
        $index = $at;
        $length = \strlen($text);
        while (true) {
            while ($index < $length && ctype_space($text[$index])) {
                ++$index;
            }
            if ($index < $length && '}' === $text[$index]) {
                return [] === $forms ? null : ['forms' => $forms, 'end' => $index + 1];
            }
            if (1 !== preg_match(self::FORM, substr($text, $index), $name)) {
                return null;
            }
            $open = $index + \strlen($name[0]);
            $depth = 1;
            $close = $open;
            for (; $close < $length && $depth > 0; ++$close) {
                if ('{' === $text[$close]) {
                    ++$depth;
                } elseif ('}' === $text[$close]) {
                    --$depth;
                }
            }
            if (0 !== $depth) {
                return null;
            }
            $forms[$name[1]] = self::parse(substr($text, $open, $close - 1 - $open));
            $index = $close;
        }
    }
}
