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

/**
 * The only acceptable licences for a **runtime** dependency — one we distribute.
 *
 * Adding one here is a licensing decision, not a build fix. Every entry is non-copyleft AND imposes no
 * obligation that survives into a commercial sublicence, which is the test that matters: `LICENSING.md`
 * obligation 1 is that a copyleft dependency satisfies our AGPL branch and **kills the commercial one**,
 * because a third party's copyleft cannot be relicensed to a customer buying an escape from disclosure.
 *
 * The four beyond the original MIT/Apache/BSD/ISC set were added when the Angular tier landed, each on its
 * own merits rather than to make a build pass:
 *
 *  - **0BSD** — Zero-clause BSD: permissive with *no* attribution requirement at all, strictly weaker in
 *    obligation than MIT. `tslib`, TypeScript's own runtime helper, is the reason it is needed, and it is
 *    the only non-MIT/ISC/Apache/BSD licence in the Angular tier's **runtime** tree.
 *  - **MIT-0** — MIT No Attribution: MIT with the notice requirement removed.
 *  - **CC0-1.0** — a public-domain dedication, so there is no licence to comply with.
 *  - **BlueOak-1.0.0** — the Blue Oak Model License, OSI-approved and written as a modern MIT/BSD
 *    replacement that also grants patent rights. Non-copyleft.
 *
 * Deliberately still absent: every GPL/AGPL/LGPL/MPL/EPL identifier, and **CC-BY**, which is an
 * attribution licence for creative works — see PERMISSIVE_FOR_BUILD_TIME_DATA below for why that one is
 * handled separately rather than added here.
 */
const PERMISSIVE = [
    'MIT',
    'Apache-2.0',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'ISC',
    '0BSD',
    'MIT-0',
    'CC0-1.0',
    'BlueOak-1.0.0',
];

/**
 * Additionally acceptable for a **dev-only** dependency, which is never distributed.
 *
 * `CC-BY-4.0` and `CC-BY-3.0` are Creative Commons *content* licences: no copyleft, so they do not
 * threaten the commercial branch, but they do impose an **attribution** requirement — and this project has
 * already refused a dependency for exactly that reason (licensing invariant 3: `admin-portal`'s Attribution
 * Assurance License would have required a prominent credit on every launch, forever). So they are not
 * granted blanket permission; they are permitted only where no obligation can attach, which is a package
 * consumed at build time and absent from the shipped artifact.
 *
 * The two that need it are both reference *data*, not code: `caniuse-lite` (browser-support tables, read by
 * browserslist while compiling) and `spdx-exceptions` (a list of SPDX identifiers). If either ever appears
 * as a runtime dependency, the strict list above applies and the gate fails — which is the point of
 * splitting the two lists rather than widening one.
 */
const PERMISSIVE_FOR_BUILD_TIME_DATA = ['CC-BY-4.0', 'CC-BY-3.0'];

/**
 * Lock files to inspect, per tier. A tier that does not exist yet is reported as skipped rather than
 * passing silently — a green gate that checked nothing is worse than a red one.
 */
const LOCK_FILES = [
    'Symfony API' => '/api/composer.lock',
    'Angular admin' => '/admin/package-lock.json',
];

/**
 * The notices file every dependency must appear in — licensing invariant 8(a).
 *
 * Only DIRECT requirements are checked by name. Transitive ones are covered by the aggregate licence
 * count that file carries for the whole locked tree; enumerating 106 rows by hand would rot on the first
 * `composer update`, whereas a direct requirement is a deliberate choice somebody made and must record.
 */
const NOTICES = '/THIRD-PARTY-NOTICES.md';

/**
 * Lock files owed by tiers that do not exist yet, so the gap stays visible.
 *
 * A tier moves OUT of this list and into LOCK_FILES the moment its lock file exists. Leaving it here would
 * print a reassuring "owed — Wave 8" line while the file sat unchecked beside it, which is precisely what
 * happened when the Angular tier was scaffolded: 658 locked packages, six licence identifiers not on the
 * permissive list, and this gate still reported OK.
 */
const OWED = [
    'Flutter client' => '/mobile/pubspec.lock (Wave 11)',
];

exit(main());

function main(): int
{
    $offending = [];
    $checked = 0;
    $skipped = [];

    foreach (LOCK_FILES as $tier => $relativePath) {
        $path = REPO_ROOT . $relativePath;

        if (!is_file($path)) {
            $skipped[] = $tier . ' (' . ltrim($relativePath, '/') . ' absent)';

            continue;
        }

        $packages = str_ends_with($path, '.json') && str_contains($path, 'package-lock')
            ? npmPackages($path)
            : composerPackages($path);

        foreach ($packages as $package) {
            ++$checked;
            $licences = $package['license'];

            if ([] === $licences) {
                $offending[] = sprintf('%s: %s declares NO licence.', $tier, $package['name']);

                continue;
            }

            // Dev-only dependencies are never distributed, so build-time DATA licences are tolerated for
            // them and for nothing else. See PERMISSIVE_FOR_BUILD_TIME_DATA for why that is a separate list
            // rather than four more entries on the strict one.
            $acceptable = $package['dev']
                ? [...PERMISSIVE, ...PERMISSIVE_FOR_BUILD_TIME_DATA]
                : PERMISSIVE;

            // A package offering a choice of licences is fine if ANY of them is acceptable: we may
            // simply take that one. A package offering only copyleft options is not.
            $permissive = array_values(array_filter(
                $licences,
                static fn(string $licence): bool => in_array($licence, $acceptable, true),
            ));

            if ([] === $permissive) {
                $offending[] = sprintf(
                    '%s: %s is %s — not permissive%s.',
                    $tier,
                    $package['name'],
                    implode(' OR ', $licences),
                    $package['dev'] ? ' (dev-only)' : ' (RUNTIME — we distribute this)',
                );
            }
        }
    }

    // Licensing invariant 8(a): recorded in THIRD-PARTY-NOTICES.md "in the same change that adds it".
    // CLAUDE.md's Licensing gate row promises this check; an earlier version of this gate did not make it,
    // so a new permissive dependency could land with no notices row and the gate still reported OK.
    $noticesPath = REPO_ROOT . NOTICES;

    if (!is_file($noticesPath)) {
        fwrite(\STDERR, "dependency-licences: FAIL — " . NOTICES . " is missing.\n");

        return 1;
    }

    $notices = file_get_contents($noticesPath);

    if (false === $notices) {
        fwrite(\STDERR, "dependency-licences: FAIL — could not read " . NOTICES . ".\n");

        return 1;
    }

    foreach (directRequirements() as $tier => $names) {
        foreach ($names as $name) {
            if (!str_contains($notices, $name)) {
                $offending[] = sprintf(
                    '%s: %s is a DIRECT requirement but does not appear in %s.',
                    $tier,
                    $name,
                    ltrim(NOTICES, '/'),
                );
            }
        }
    }

    foreach ($skipped as $tier) {
        fwrite(\STDOUT, 'dependency-licences: skipped ' . $tier . "\n");
    }

    foreach (OWED as $tier => $note) {
        fwrite(\STDOUT, 'dependency-licences: owed — ' . $tier . ': ' . $note . "\n");
    }

    if ([] !== $offending) {
        fwrite(\STDERR, "dependency-licences: FAIL\n\n");

        foreach ($offending as $line) {
            fwrite(\STDERR, '  ' . $line . "\n");
        }

        fwrite(\STDERR, <<<'TEXT'

            Permissive means MIT, Apache-2.0, BSD-2-Clause, BSD-3-Clause, ISC, 0BSD, MIT-0, CC0-1.0
            or BlueOak-1.0.0. A DEV-ONLY dependency may additionally carry CC-BY-4.0 or CC-BY-3.0,
            which are content licences tolerated only for build-time reference data that is never
            distributed -- the same list appears in this gate's PERMISSIVE constants.

            A copyleft dependency satisfies twes-in's AGPL branch and kills its commercial branch, which
            is why "AGPL-compatible" is not the test. Find a permissive alternative, or raise it with the
            developer as an explicit licensing decision (CLAUDE.md, licensing invariant 8a).

            TEXT);

        return 1;
    }

    if (0 === $checked) {
        fwrite(\STDERR, "dependency-licences: FAIL — no lock file was inspected, so nothing was verified.\n");

        return 1;
    }

    fwrite(\STDOUT, sprintf(
        "dependency-licences: OK — %d package(s) all permissively licensed.\n",
        $checked,
    ));

    return 0;
}

/**
 * Direct requirements per tier, read from the manifest rather than the lock.
 *
 * Only direct ones: the notices file records these individually and the full tree in aggregate.
 *
 * @return array<string, list<string>>
 */
function directRequirements(): array
{
    $manifestPath = REPO_ROOT . '/api/composer.json';

    if (!is_file($manifestPath)) {
        return [];
    }

    $raw = file_get_contents($manifestPath);

    if (false === $raw) {
        return [];
    }

    /** @var array{require?: array<string, string>, 'require-dev'?: array<string, string>} $manifest */
    $manifest = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

    $names = [];

    foreach ([...array_keys($manifest['require'] ?? []), ...array_keys($manifest['require-dev'] ?? [])] as $name) {
        // Platform requirements (php, ext-*, lib-*) are not packages and carry no notice obligation.
        if ('php' === $name || str_starts_with($name, 'ext-') || str_starts_with($name, 'lib-')) {
            continue;
        }

        $names[] = $name;
    }

    $tiers = [] === $names ? [] : ['Symfony API' => $names];

    // The Angular tier's direct dependencies carry the same obligation. Read from package.json, not the
    // lock: "direct" means somebody chose it, and only the manifest records that choice.
    $npmNames = npmDirectRequirements(REPO_ROOT . '/admin/package.json');

    if ([] !== $npmNames) {
        $tiers['Angular admin'] = $npmNames;
    }

    return $tiers;
}

/**
 * The names a package.json asks for directly.
 *
 * @return list<string>
 */
function npmDirectRequirements(string $manifestPath): array
{
    if (!is_file($manifestPath)) {
        return [];
    }

    $raw = file_get_contents($manifestPath);

    if (false === $raw) {
        return [];
    }

    /** @var array{dependencies?: array<string, string>, devDependencies?: array<string, string>} $manifest */
    $manifest = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

    return array_values([
        ...array_keys($manifest['dependencies'] ?? []),
        ...array_keys($manifest['devDependencies'] ?? []),
    ]);
}

/**
 * @return list<array{name: string, license: list<string>}>
 */
function composerPackages(string $lockPath): array
{
    $raw = file_get_contents($lockPath);

    if (false === $raw) {
        fwrite(\STDERR, "Could not read {$lockPath}\n");
        exit(1);
    }

    /** @var array{packages?: list<array<string, mixed>>, 'packages-dev'?: list<array<string, mixed>>} $lock */
    $lock = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

    $packages = [];

    // Dev dependencies count. They are not shipped, but they are still code this project distributes
    // instructions to download, and a copyleft test runner would still need reporting in the notices. The
    // `dev` flag is carried through rather than discarded, because it decides which licence list applies.
    foreach ($lock['packages'] ?? [] as $package) {
        /** @var array{name?: string, license?: list<string>} $package */
        $packages[] = [
            'name' => $package['name'] ?? '(unnamed)',
            'license' => $package['license'] ?? [],
            'dev' => false,
        ];
    }

    foreach ($lock['packages-dev'] ?? [] as $package) {
        /** @var array{name?: string, license?: list<string>} $package */
        $packages[] = [
            'name' => $package['name'] ?? '(unnamed)',
            'license' => $package['license'] ?? [],
            'dev' => true,
        ];
    }

    return $packages;
}

/**
 * Every package in an npm lock file, with its licence.
 *
 * npm's `lockfileVersion: 3` records a `license` for essentially every entry, which is what makes this
 * checkable **without `node_modules`** — and that matters, because `node_modules` is gitignored and absent
 * in CI before `npm ci`, so a gate that read package.json files on disk would silently check nothing there.
 *
 * Two shapes to normalise. Keys are paths (`node_modules/@scope/name`), not names, and the same package can
 * appear several times at different nesting depths — each is reported separately, deliberately, because two
 * versions of one package can carry two different licences. The ROOT entry (key `""`) is our own project and
 * is skipped: it has no `license` field and it is not a dependency.
 *
 * @return list<array{name: string, license: list<string>, dev: bool}>
 */
function npmPackages(string $lockPath): array
{
    $raw = file_get_contents($lockPath);

    if (false === $raw) {
        fwrite(\STDERR, "Could not read {$lockPath}\n");
        exit(1);
    }

    /** @var array{lockfileVersion?: int, packages?: array<string, array<string, mixed>>} $lock */
    $lock = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);

    // A version bump could change the schema out from under this parser, and reading nothing would look
    // exactly like a clean tree. Refuse rather than guess.
    $version = $lock['lockfileVersion'] ?? 0;

    if (3 !== $version) {
        fwrite(\STDERR, sprintf(
            "dependency-licences: FAIL — %s has lockfileVersion %s; this gate reads version 3, where npm "
            . "records a licence per entry. Re-check the schema before widening this.\n",
            $lockPath,
            var_export($version, true),
        ));
        exit(1);
    }

    $packages = [];

    foreach ($lock['packages'] ?? [] as $path => $package) {
        if ('' === $path) {
            continue;
        }

        /** @var array{license?: string|list<string>, dev?: bool, name?: string} $package */
        $licence = $package['license'] ?? null;

        $packages[] = [
            // The path, not just the basename: with nested duplicates the basename alone cannot tell an
            // operator WHICH copy is the problem.
            'name' => $path,
            'license' => match (true) {
                null === $licence => [],
                \is_string($licence) => [$licence],
                default => $licence,
            },
            'dev' => true === ($package['dev'] ?? false),
        ];
    }

    return $packages;
}
