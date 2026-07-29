#!/usr/bin/env php
<?php

/*
 * Gate: every dependency is permissively licensed.
 *
 * Why it exists, and why "AGPL-compatible" is the WRONG test. twes-in is AGPL-3.0-or-later **plus a
 * commercial licence** (LICENSING.md). A GPL/AGPL/LGPL dependency satisfies the AGPL branch perfectly
 * and **destroys the commercial one**: a third party's copyleft code cannot be relicensed to a customer
 * who is buying an escape from source disclosure. So the test is permissive-only — MIT, Apache-2.0,
 * BSD-2-Clause, BSD-3-Clause, ISC — and nothing else.
 *
 * What breaks without it: one `composer require` during a rushed afternoon quietly removes the ability
 * to sell this software, and nobody notices until a lawyer reads the dependency tree. A habit will not
 * catch that. This will.
 *
 * A genuinely needed copyleft-only library is a decision for the developer to make explicitly — see
 * CLAUDE.md, licensing invariant 8(a) — never a silent one.
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
                $offending[] = \sprintf('%s: %s declares NO licence.', $tier, $package['name']);

                continue;
            }

            // A package offering a choice of licences is fine if ANY of them is permissive: we may
            // simply take that one. A package offering only copyleft options is not.
            $permissive = array_values(array_filter(
                $licences,
                static fn (string $licence): bool => \in_array($licence, PERMISSIVE, true),
            ));

            if ([] === $permissive) {
                $offending[] = \sprintf(
                    '%s: %s is %s — not permissive.',
                    $tier,
                    $package['name'],
                    implode(' OR ', $licences),
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

    fwrite(\STDOUT, \sprintf(
        "dependency-licences: OK — %d package(s) all permissively licensed.\n",
        $checked,
    ));

    return 0;
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
