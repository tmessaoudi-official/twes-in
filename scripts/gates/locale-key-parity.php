#!/usr/bin/env php
<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

const REPO_ROOT = __DIR__ . '/../..';
const TRANSLATIONS = REPO_ROOT . '/api/translations';

/** Locales that must all be present and in parity. */
const REQUIRED_LOCALES = ['en', 'fr', 'ar'];

const OWED = [
    'Angular admin' => 'admin/src/locale (Wave 8)',
    'Flutter client' => 'mobile/lib/l10n (Wave 11)',
];

exit(main());

function main(): int
{
    // THE RULES, INTROSPECTABLE. Added 2026-08-23 (round 4, R4K-5): `CLAUDE.md` claims "every gate answers
    // `--dump-rules`" and this was one of two that did not — and it did not merely stay silent, it ran the whole
    // gate and printed its ordinary verdict, so `--dump-rules` and no flag at all produced byte-identical output.
    // A consumer could not distinguish "no rules to dump" from "your flag was ignored". `test-gates.sh` now
    // asserts the universal by DERIVING the gate list from `git ls-files` instead of naming eight of them.
    //
    // Dumped BEFORE the `owed` notices, unlike the run path, because a dump is data for a consumer rather than a
    // report for a human — mixing the two is what made the flag indistinguishable from a run in the first place.
    if (in_array('--dump-rules', array_slice($_SERVER['argv'] ?? [], 1), true)) {
        fwrite(\STDOUT, 'translations api/translations' . "\n");
        fwrite(\STDOUT, 'required_locales ' . implode(' ', REQUIRED_LOCALES) . "\n");

        foreach (OWED as $tier => $note) {
            fwrite(\STDOUT, 'owed ' . $tier . ' => ' . $note . "\n");
        }

        return 0;
    }

    foreach (OWED as $tier => $note) {
        fwrite(\STDOUT, 'locale-key-parity: owed — ' . $tier . ': ' . $note . "\n");
    }

    if (!is_dir(TRANSLATIONS)) {
        fwrite(\STDERR, "locale-key-parity: FAIL — api/translations does not exist.\n");

        return 1;
    }

    $domains = discoverDomains();

    if ([] === $domains) {
        fwrite(\STDERR, "locale-key-parity: FAIL — no XLIFF catalogues found, so nothing was verified.\n");

        return 1;
    }

    $problems = [];

    foreach ($domains as $domain => $byLocale) {
        foreach (REQUIRED_LOCALES as $locale) {
            if (!isset($byLocale[$locale])) {
                $problems[] = sprintf('domain "%s" has no %s catalogue.', $domain, $locale);
            }
        }

        // The reference is the union of every key seen in any locale, not one designated locale. Using
        // English as the reference would hide a key that exists only in French — still a drift, and
        // still a key some code path expects to find everywhere.
        $union = [];

        foreach ($byLocale as $keys) {
            foreach ($keys as $key) {
                $union[$key] = true;
            }
        }

        $allKeys = array_keys($union);
        sort($allKeys);

        foreach ($byLocale as $locale => $keys) {
            $missing = array_values(array_diff($allKeys, $keys));
            sort($missing);

            foreach ($missing as $key) {
                $problems[] = sprintf('domain "%s", locale "%s": missing key "%s".', $domain, $locale, $key);
            }

            $duplicates = array_values(array_diff_assoc($keys, array_unique($keys)));

            foreach ($duplicates as $key) {
                $problems[] = sprintf('domain "%s", locale "%s": duplicate key "%s".', $domain, $locale, $key);
            }
        }
    }

    if ([] !== $problems) {
        fwrite(\STDERR, "locale-key-parity: FAIL\n\n");

        foreach ($problems as $problem) {
            fwrite(\STDERR, '  ' . $problem . "\n");
        }

        return 1;
    }

    $total = 0;

    foreach ($domains as $byLocale) {
        $total += count(reset($byLocale) ?: []);
    }

    fwrite(\STDOUT, sprintf(
        "locale-key-parity: OK — %d domain(s), %d key(s) each, across %s.\n",
        count($domains),
        $total,
        implode('/', REQUIRED_LOCALES),
    ));

    return 0;
}

/**
 * Catalogues on disk, as domain => locale => keys.
 *
 * @return array<string, array<string, list<string>>>
 */
function discoverDomains(): array
{
    $domains = [];

    foreach (glob(TRANSLATIONS . '/*.xlf') ?: [] as $path) {
        $name = basename($path, '.xlf');

        // Symfony's convention is <domain>.<locale>.xlf.
        if (1 !== preg_match('/\A(?<domain>.+)\.(?<locale>[a-z]{2}(?:_[A-Z]{2})?)\z/', $name, $matches)) {
            fwrite(\STDERR, sprintf(
                "locale-key-parity: FAIL — \"%s\" is not named <domain>.<locale>.xlf.\n",
                basename($path),
            ));
            exit(1);
        }

        $domains[$matches['domain']][$matches['locale']] = keysIn($path);
    }

    return $domains;
}

/** @return list<string> */
function keysIn(string $path): array
{
    $previous = libxml_use_internal_errors(true);
    $document = simplexml_load_file($path);
    libxml_use_internal_errors($previous);

    if (false === $document) {
        fwrite(\STDERR, sprintf("locale-key-parity: FAIL — %s is not valid XML.\n", basename($path)));
        exit(1);
    }

    $keys = [];

    foreach ($document->xpath('//*[local-name()="trans-unit"]') ?: [] as $unit) {
        $attributes = $unit->attributes();
        $resname = $attributes['resname'] ?? null;

        // resname is the stable identifier; <source> is what a translator may legitimately reword.
        $keys[] = null !== $resname ? (string) $resname : (string) $unit->source;
    }

    return $keys;
}
