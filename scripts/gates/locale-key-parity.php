#!/usr/bin/env php
<?php

/*
 * Gate: every locale carries exactly the same set of translation keys.
 *
 * Why it exists: a missing key does not crash anything. Symfony's translator falls back to echoing the
 * key itself, so the failure surfaces as `error.invoice.already_paid` printed on a customer's invoice —
 * discovered by the customer, not by the suite. twes-in ships French, Arabic and English from the first
 * version (France and Tunisia both go live in Wave 5), so three catalogues have to move together.
 *
 * What breaks without it: catalogues drift one key at a time, and the locale nobody on the team reads
 * is the one that rots. Arabic is that locale here.
 *
 * This is the API tier's catalogues. The Angular admin's and the Flutter client's are checked the same
 * way when those tiers land; see OWED below.
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
                $problems[] = \sprintf('domain "%s" has no %s catalogue.', $domain, $locale);
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
                $problems[] = \sprintf('domain "%s", locale "%s": missing key "%s".', $domain, $locale, $key);
            }

            $duplicates = array_values(array_diff_assoc($keys, array_unique($keys)));

            foreach ($duplicates as $key) {
                $problems[] = \sprintf('domain "%s", locale "%s": duplicate key "%s".', $domain, $locale, $key);
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
        $total += \count(reset($byLocale) ?: []);
    }

    fwrite(\STDOUT, \sprintf(
        "locale-key-parity: OK — %d domain(s), %d key(s) each, across %s.\n",
        \count($domains),
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
            fwrite(\STDERR, \sprintf(
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
        fwrite(\STDERR, \sprintf("locale-key-parity: FAIL — %s is not valid XML.\n", basename($path)));
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
