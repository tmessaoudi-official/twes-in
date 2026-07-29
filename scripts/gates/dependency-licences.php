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

/** The only acceptable licences. Adding one here is a licensing decision, not a build fix. */
const PERMISSIVE = ['MIT', 'Apache-2.0', 'BSD-2-Clause', 'BSD-3-Clause', 'ISC'];

/**
 * Lock files to inspect, per tier. A tier that does not exist yet is reported as skipped rather than
 * passing silently — a green gate that checked nothing is worse than a red one.
 */
const LOCK_FILES = [
    'Symfony API' => '/api/composer.lock',
];

/**
 * The notices file every dependency must appear in — licensing invariant 8(a).
 *
 * Only DIRECT requirements are checked by name. Transitive ones are covered by the aggregate licence
 * count that file carries for the whole locked tree; enumerating 106 rows by hand would rot on the first
 * `composer update`, whereas a direct requirement is a deliberate choice somebody made and must record.
 */
const NOTICES = '/THIRD-PARTY-NOTICES.md';

/** Lock files owed by tiers that do not exist yet, so the gap stays visible. */
const OWED = [
    'Angular admin' => '/admin/package-lock.json (Wave 8)',
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

        foreach (composerPackages($path) as $package) {
            ++$checked;
            $licences = $package['license'];

            if ([] === $licences) {
                $offending[] = sprintf('%s: %s declares NO licence.', $tier, $package['name']);

                continue;
            }

            // A package offering a choice of licences is fine if ANY of them is permissive: we may
            // simply take that one. A package offering only copyleft options is not.
            $permissive = array_values(array_filter(
                $licences,
                static fn(string $licence): bool => in_array($licence, PERMISSIVE, true),
            ));

            if ([] === $permissive) {
                $offending[] = sprintf(
                    '%s: %s is %s — not permissive.',
                    $tier,
                    $package['name'],
                    implode(' OR ', $licences),
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

            Permissive means MIT, Apache-2.0, BSD-2-Clause, BSD-3-Clause or ISC.

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

    return [] === $names ? [] : ['Symfony API' => $names];
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
    // instructions to download, and a copyleft test runner would still need reporting in the notices.
    foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
        /** @var array{name?: string, license?: list<string>} $package */
        $packages[] = [
            'name' => $package['name'] ?? '(unnamed)',
            'license' => $package['license'] ?? [],
        ];
    }

    return $packages;
}
