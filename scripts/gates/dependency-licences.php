#!/usr/bin/env php
<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

require __DIR__.'/../lib/dependency-inventory.php';

/*
 * The licence gate. Every list below is a MAXIMUM: widening one is a licensing decision recorded in
 * LICENSING.md and CLAUDE.md, never a build fix. tests/dependency-licences.test.sh pins each list.
 */

/** Anything we DISTRIBUTE: non-copyleft, and no obligation that survives into a commercial sublicence. */
const DISTRIBUTED = ['MIT', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC', '0BSD', 'MIT-0', 'CC0-1.0', 'BlueOak-1.0.0'];

/** Dev-only build-time DATA (caniuse-lite, spdx-exceptions): attribution licences, tolerated only where nothing ships. */
const DEV_ONLY_DATA = ['CC-BY-4.0', 'CC-BY-3.0'];

/** Dev-only build-time TOOLING (lightningcss inside Angular's build): file-level copyleft, never in a shipped bundle. */
const DEV_ONLY_TOOLING = ['MPL-2.0'];

/**
 * Vendored FONT FILES (not packages) may carry OFL-1.1. No font is vendored yet; the file-level check lands with
 * the first one (G2, design system). Listed here so the maximum is pinned before it is needed.
 */
const FONT_ASSETS = ['OFL-1.1'];

const OWN_LICENCE = 'AGPL-3.0-or-later';

$args = parseArguments($argv);

if (in_array('--dump-rules', $args['flags'], true)) {
    echo json_encode(['distributed' => DISTRIBUTED, 'dev_only_data' => DEV_ONLY_DATA, 'dev_only_tooling' => DEV_ONLY_TOOLING, 'font_assets' => FONT_ASSETS], JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$root = $args['root'];
$violations = [];

foreach (['api/composer.json', 'web/package.json'] as $manifest) {
    $declared = (string) (readJson($root.'/'.$manifest)['license'] ?? '');
    if (OWN_LICENCE !== $declared) {
        $violations[] = sprintf("%s declares '%s', expected '%s'", $manifest, $declared, OWN_LICENCE);
    }
}

$records = dependencyInventory($root);
foreach ($records as $r) {
    $label = sprintf('%s/%s', $r['tier'], $r['name']);
    if ('' === $r['licence']) {
        $violations[] = sprintf('%s declares no licence', $label);
        continue;
    }
    $allowed = $r['dev'] ? [...DISTRIBUTED, ...DEV_ONLY_DATA, ...DEV_ONLY_TOOLING] : DISTRIBUTED;
    if (!expressionIsPermitted($r['licence'], $allowed)) {
        $violations[] = $r['dev']
            ? sprintf('%s (%s) is a DEV dependency outside the permitted dev lists', $label, $r['licence'])
            : sprintf('%s (%s) is a RUNTIME dependency outside the nine permitted identifiers', $label, $r['licence']);
    }
}

$notices = $root.'/THIRD-PARTY-NOTICES.md';
if (!is_file($notices) || file_get_contents($notices) !== renderNotices($records)) {
    $violations[] = 'THIRD-PARTY-NOTICES.md is out of date — run: php scripts/notices/generate-third-party-notices.php';
}

if ([] !== $violations) {
    fwrite(STDERR, "dependency-licences: FAIL\n  ".implode("\n  ", $violations)."\n");
    exit(1);
}

$runtime = count(array_filter($records, static fn (array $r): bool => !$r['dev']));
printf("dependency-licences: OK — %d runtime and %d dev packages across api and web, notices current\n", $runtime, count($records) - $runtime);
exit(0);

/**
 * SPDX expressions: "(MIT OR Apache-2.0)" passes if ANY branch is permitted; "(MIT AND GPL-2.0-only)" only if ALL are.
 * Anything that is not an identifier from the lists ("SEE LICENSE IN LICENSE.md", "Python-2.0") is refused.
 *
 * @param list<string> $allowed
 */
function expressionIsPermitted(string $expression, array $allowed): bool
{
    $bare = trim(str_replace(['(', ')'], ' ', $expression));
    if (preg_match('/\bAND\b/', $bare)) {
        $parts = preg_split('/\s+AND\s+/', $bare) ?: [];
        foreach ($parts as $part) {
            if (!expressionIsPermitted($part, $allowed)) {
                return false;
            }
        }

        return true;
    }
    foreach (preg_split('/\s+OR\s+/', $bare) ?: [] as $part) {
        if (in_array(trim($part), $allowed, true)) {
            return true;
        }
    }

    return false;
}
