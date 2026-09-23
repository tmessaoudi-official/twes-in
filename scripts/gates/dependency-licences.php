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

/**
 * Anything we DISTRIBUTE: non-copyleft, and no obligation that survives into a commercial sublicence. Unicode-3.0 was
 * added 2026-09-23 (developer ruling) for the Unicode character tables the camera's barcode decoder compiles in.
 */
const DISTRIBUTED = ['MIT', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC', '0BSD', 'MIT-0', 'CC0-1.0', 'BlueOak-1.0.0', 'Unicode-3.0'];

/** Dev-only build-time DATA (caniuse-lite, spdx-exceptions): attribution licences, tolerated only where nothing ships. */
const DEV_ONLY_DATA = ['CC-BY-4.0', 'CC-BY-3.0'];

/**
 * Dev-only build-time TOOLING: MPL-2.0 (lightningcss inside Angular's build, file-level copyleft) and Python-2.0
 * (argparse under @hey-api/openapi-ts, the OpenAPI-to-TypeScript generator; permissive, non-copyleft). Neither
 * reaches a shipped bundle.
 */
const DEV_ONLY_TOOLING = ['MPL-2.0', 'Python-2.0'];

/**
 * Vendored FONT FILES (not packages) under web/public and web/src may carry OFL-1.1, and only with the licence text
 * in a LICENSE file beside them. An OFL-1.1 package is still a refused runtime dependency.
 */
const FONT_ASSETS = ['OFL-1.1'];

/**
 * SPDX exceptions accepted after `WITH` on a component compiled into vendored WebAssembly, the base licence still
 * being checked: LLVM's only waives attribution for compiled-in portions of libc++ (developer ruling 2026-09-22 22:38,
 * "Apache-2.0 WITH LLVM-exception is accepted as Apache-2.0").
 */
const EXCEPTIONS = ['LLVM-exception'];

const OWN_LICENCE = 'AGPL-3.0-or-later';

$args = parseArguments($argv);

if (in_array('--dump-rules', $args['flags'], true)) {
    echo json_encode(['distributed' => DISTRIBUTED, 'dev_only_data' => DEV_ONLY_DATA, 'dev_only_tooling' => DEV_ONLY_TOOLING, 'font_assets' => FONT_ASSETS, 'exceptions' => EXCEPTIONS], JSON_PRETTY_PRINT), "\n";
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
            : sprintf('%s (%s) is a RUNTIME dependency outside the permitted identifiers', $label, $r['licence']);
    }
}

$fonts = vendoredFonts($root);
foreach ($fonts as $f) {
    if ('' === $f['licence']) {
        foreach ($f['files'] as $file) {
            $violations[] = sprintf('%s/%s is a vendored font with no LICENSE file beside it', $f['dir'], $file);
        }
    } elseif (!in_array($f['licence'], FONT_ASSETS, true)) {
        $violations[] = sprintf('%s/LICENSE is not a permitted font licence (%s)', $f['dir'], implode(', ', FONT_ASSETS));
    }
}

// What vendored WebAssembly compiles in: each manifest is the audit of the exact tarball the lock installs, every
// component is permitted, and its licence text is there to ship beside the wasm.
$wasm = vendoredWasm($root);
$locked = readJson($root.'/web/package-lock.json')['packages'] ?? [];
foreach ($wasm as $w) {
    $entry = (array) ($locked['node_modules/'.$w['package']] ?? []);
    $version = (string) ($entry['version'] ?? '');
    $integrity = (string) ($entry['integrity'] ?? '');
    if ($version !== $w['version'] || $integrity !== $w['integrity']) {
        $violations[] = sprintf('%s/COMPONENTS.json audits %s %s (%s) but web/package-lock.json holds %s (%s): read the new wasm before shipping it', $w['dir'], $w['package'], $w['version'], $w['integrity'], '' === $version ? 'nothing' : $version, $integrity);
    }
    foreach ($w['components'] as $c) {
        if (!componentIsPermitted($c['licence'])) {
            $violations[] = sprintf('%s compiles in %s (%s), outside the permitted identifiers', $w['package'], $c['name'], $c['licence']);
        }
        $text = $root.'/'.$w['dir'].'/'.$c['text'];
        if ('' === $c['text'] || !is_file($text) || '' === trim((string) file_get_contents($text))) {
            $violations[] = sprintf('%s/%s, the licence text of %s, is missing or empty', $w['dir'], $c['text'], $c['name']);
        }
    }
}
$audited = array_map(static fn (array $w): string => $w['package'], $wasm);
foreach (shippedWasmPackages($root) as $package) {
    if (!in_array($package, $audited, true)) {
        $violations[] = sprintf("web/angular.json ships %s's WebAssembly, and no web/src/third-party/*/COMPONENTS.json lists what it compiles in", $package);
    }
}

$notices = $root.'/THIRD-PARTY-NOTICES.md';
if (!is_file($notices) || file_get_contents($notices) !== renderNotices($records, $fonts, $wasm)) {
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
 * Anything that is not an identifier from the lists ("SEE LICENSE IN LICENSE.md", "GPL-3.0-only") is refused.
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

/** A compiled-in component's licence: a permitted expression, or a permitted identifier WITH an accepted exception. */
function componentIsPermitted(string $licence): bool
{
    if (1 === preg_match('/^(\S+)\s+WITH\s+(\S+)$/', trim($licence), $m)) {
        return in_array($m[2], EXCEPTIONS, true) && in_array($m[1], DISTRIBUTED, true);
    }

    return '' !== trim($licence) && expressionIsPermitted($licence, DISTRIBUTED);
}
