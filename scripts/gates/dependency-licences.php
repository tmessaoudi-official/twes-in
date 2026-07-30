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
 * Additionally acceptable for a vendored **font asset** (developer ruling, 2026-07-29).
 *
 * `OFL-1.1` — the SIL Open Font License — is the licence essentially every open font ships under, including
 * the Noto family. It is **not** copyleft for our purposes and imposes nothing on our code: its one real
 * obligation is the **Reserved Font Name** clause, which binds only somebody who MODIFIES the font and
 * redistributes it under its original name. Vendoring a font unmodified triggers none of it, and the licence
 * text travels beside the file.
 *
 * A third narrow category rather than a tenth entry on PERMISSIVE, for the same reason the CC-BY pair is
 * quarantined: it is permitted where no obligation can attach, and a *code* dependency under OFL-1.1 would be
 * a different question that this list must not silently answer. The gate refuses OFL-1.1 on any Composer, npm
 * or pub package.
 *
 * Why it was needed: Flutter Web's engine downloads Noto fallback fonts from `fonts.gstatic.com` for any
 * script the bundled fonts do not cover, and `ar` is a first-class locale here. Pinning the origin stopped the
 * transfer to Google; rendering Arabic at all requires the font to be ours.
 */
const PERMISSIVE_FOR_FONT_ASSETS = ['OFL-1.1'];

/**
 * Directories holding vendored font binaries, per tier.
 *
 * This exists because the constant above was, for one commit, a **permission nothing consulted**: it was
 * declared, documented and dumped by --dump-rules, and no code path read it. That is the same
 * prose-versus-enforcement shape CLAUDE.md § Gotchas already records twice, and it is worse here than
 * elsewhere — a licence category that is written down but unenforced reads as due diligence while
 * permitting anything.
 *
 * A font is one of the dependency classes the lock-file walk structurally cannot see: it arrives as a
 * committed binary, not as a manifest entry, so `composer.lock` and `package-lock.json` are both blind to it.
 * (It is not the ONLY one — 13 of the 37 tracked `.png`/`.ico` files are byte-identical to a Flutter-SDK or
 * Angular-schematic template asset and ship too. Round 8 found that sentence overstated here, and an
 * overstatement that says "the class is closed at one member" is how the next member goes unlooked-for.)
 *
 * Each entry is a tier, and each tier names its own manifest, because the "is the licence text actually
 * SHIPPED?" question is answered by a different file per tier and validating one tier's asset against
 * another's manifest would be worse than not checking.
 */
const FONT_ASSET_DIRECTORIES = [
    'Flutter client' => ['fonts' => '/mobile/assets/fonts', 'manifest' => '/mobile/pubspec.yaml'],
];

/**
 * Non-font files the font tree is allowed to contain.
 *
 * Everything else under a font directory is a **violation**, which is the inverse of how this walk first
 * worked: it filtered *in* the extensions it understood, so a `Proprietary-Regular.font` with no sidecar and
 * no notices row passed with the count never moving. An allowlist fails closed on a container nobody
 * anticipated; an extension filter fails open on exactly that.
 */
const FONT_DIRECTORY_COMPANION_FILES = ['txt', 'license', 'md'];

/**
 * The largest font this gate will read into memory.
 *
 * `file_get_contents()` loads the whole binary, so a 100 MB `.ttf` aborted the gate with a PHP fatal and a
 * stack trace instead of a message. That failed closed (exit 255), but this project has recorded three times
 * that a crash and a detection are indistinguishable to anybody reading output — so the ceiling produces a
 * real violation. No legitimate UI font is anywhere near this: the largest here is 253 KB.
 */
const MAX_FONT_BYTES = 8 * 1024 * 1024;

/**
 * Font binaries the FRAMEWORK injects into the artifact, which we therefore distribute without vendoring.
 *
 * These cannot be found by the walk over `mobile/assets/fonts/` — they are not in the repository at all —
 * and no lock file records them either, because they arrive from a manifest *flag*. So they are enumerated
 * here, and each one's obligation is discharged by shipping its licence text, which this gate verifies.
 *
 * **The identifier recorded here is a DISCHARGED OBLIGATION, not a permission.** Nothing in this list widens
 * PERMISSIVE, and a Composer/npm/pub package under CC-BY-4.0 is still refused — the meta-suite has a case for
 * exactly that. The distinction matters: an obligation we satisfy is not the same as a licence we accept.
 *
 * `MaterialIcons-Regular.otf` reaches `build/web/assets/fonts/` from `uses-material-design: true`. Its
 * position was genuinely unclear, so under licensing invariant 10 it was put to the developer rather than
 * resolved conveniently, and the **ruling (2026-07-30) was to comply with the STRICTER reading**:
 *
 *   - the SDK ships `MaterialIcons_LICENSE.txt` beside it whose first line is "Attribution 4.0
 *     International", i.e. **CC-BY-4.0**;
 *   - Google's `material-design-icons` repository states Apache-2.0 for the icon set, a weaker obligation —
 *     but that cannot be verified from this container, because GitHub egress is restricted to this repository
 *     (see CLAUDE.md § Gotchas), so it is not the reading we rely on;
 *   - complying with CC-BY-4.0 satisfies Apache-2.0 § 4(a) as well, so the stricter reading is correct under
 *     either. Attribution, licence notice, licence URI and a **statement of modification** (the shipped copy
 *     is tree-shaken from 1645184 to ~7736 bytes) all live in `MaterialIcons-LICENSE.txt`.
 *
 * Note the binary carries **no nameID 13 at all** [Verified: parsed; only nameID 0 "Copyright 2019 Google
 * LLC" and nameID 1 "Material Icons"], so the name-table cross-check cannot run on it. That is why this is a
 * separate, explicitly enumerated list rather than a sixth branch of the vendored-font walk: the evidence
 * available here is different in kind, and pretending otherwise would make the cross-check look stronger
 * than it is.
 */
const FRAMEWORK_PROVIDED_FONTS = [
    'MaterialIcons-Regular.otf' => [
        'obligation' => 'CC-BY-4.0',
        'text' => 'MaterialIcons-LICENSE.txt',
        'source' => 'uses-material-design: true',
    ],
];

/**
 * Font containers this gate can read the licence out of, and the ones it refuses.
 *
 * `.ttf` and `.otf` are both sfnt containers and both carry the OpenType `name` table this gate
 * cross-checks. The refused set is refused **by name** rather than ignored, which is the whole point: a
 * `.woff2` is compressed and a `.ttc` holds several fonts at once, so neither can be verified by the parser
 * below — and a file the gate silently skipped would be indistinguishable from one it approved.
 */
const FONT_EXTENSIONS = ['ttf', 'otf'];
const UNVERIFIABLE_FONT_EXTENSIONS = ['woff', 'woff2', 'ttc', 'otc', 'eot', 'pfb'];

/**
 * What a font's own `name` table must say for a sidecar's SPDX identifier to be believed.
 *
 * The sidecar is written by hand, so on its own it proves only that somebody typed an identifier. Every
 * real font states its licence in nameID 13 (`License Description`), which is set by the foundry and
 * travels inside the binary — so the two can be compared, and a sidecar that disagrees with the file it
 * describes fails the gate.
 *
 * An identifier absent from this map is **refused**, not waved through: adding a font under a licence
 * whose name-table wording nobody has checked is precisely the moment to stop and look.
 */
const FONT_NAME_TABLE_EVIDENCE = [
    'OFL-1.1' => 'SIL Open Font License',
    'Apache-2.0' => 'Apache License',
    'MIT' => 'MIT License',
    'CC0-1.0' => 'CC0',
];

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
    // NOT "Wave 11" — the tier is scaffolded and its lock file exists. The real reason is narrower and
    // worth stating exactly: pubspec.lock records no licence field at all, unlike composer.lock and npm's
    // lockfileVersion 3, which both carry one per entry. So there is nothing here for this gate to read,
    // and the Flutter tier's licences are verified by hand into THIRD-PARTY-NOTICES.md instead. Closing
    // this properly needs a `flutter pub deps --json` walk with a cached licence map, or vendored LICENSE
    // files; either is a design decision, not a one-line fix. See build-waves.plan.md.
    'Flutter client' => 'mobile/pubspec.lock records NO licence field, so per-package licences cannot be '
        . 'read from it; the direct dependencies are checked against THIRD-PARTY-NOTICES.md below and their '
        . 'licences are recorded there by hand',
];

/*
 * See no-ambient-calls-in-domain.php for why gates are introspectable. This gate was the ONLY one without
 * it, and round 5 measured the consequence: `GPL-3.0`, `AGPL-3.0` and `MPL-2.0` could all be added to
 * PERMISSIVE with every meta-case still green. Growth is this list's dangerous direction — adding an
 * identifier is a legal act, not a build fix — which is why test-gates.sh asserts a MAXIMUM here, exactly as
 * it does for the SPDX exclusion list.
 */
if (isset($argv[1]) && '--dump-rules' === $argv[1]) {
    echo json_encode([
        'permissive' => PERMISSIVE,
        'build_time_data' => PERMISSIVE_FOR_BUILD_TIME_DATA,
        'font_assets' => PERMISSIVE_FOR_FONT_ASSETS,
        'font_directories' => array_values(FONT_ASSET_DIRECTORIES),
        'font_extensions' => FONT_EXTENSIONS,
        'unverifiable_font_extensions' => UNVERIFIABLE_FONT_EXTENSIONS,
        'font_companion_files' => FONT_DIRECTORY_COMPANION_FILES,
        'font_name_table_evidence' => array_keys(FONT_NAME_TABLE_EVIDENCE),
        'max_font_bytes' => MAX_FONT_BYTES,
        'framework_provided_fonts' => array_keys(FRAMEWORK_PROVIDED_FONTS),
        'lock_files' => array_values(LOCK_FILES),
        // UNESCAPED_SLASHES so a path reads as /api/composer.lock rather than \/api\/composer.lock — the
        // meta-suite greps this output, and an escaped slash makes a correct assertion fail confusingly.
    ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), "\n";

    exit(0);
}

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

    // Vendored fonts. Checked separately from $checked so the "no lock file was inspected" guard below
    // keeps meaning what it says — a run that read five fonts and zero lock files has still verified
    // nothing about the dependency tree.
    $fontsChecked = 0;

    foreach (FONT_ASSET_DIRECTORIES as $tier => $paths) {
        $directory = REPO_ROOT . $paths['fonts'];

        if (!is_dir($directory)) {
            $skipped[] = $tier . ' fonts (' . ltrim($paths['fonts'], '/') . ' absent)';

            continue;
        }

        $violations = fontViolations($directory, REPO_ROOT . $paths['manifest'], $notices, $fontsChecked);

        foreach ($violations as $violation) {
            $offending[] = $tier . ': ' . $violation;
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
            distributed. A vendored FONT ASSET may carry OFL-1.1. None of the three lists applies to
            the others -- an OFL-1.1 *package* is refused, as is a CC-BY runtime dependency.

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
        "dependency-licences: OK — %d package(s) and %d vendored font(s) all permissively licensed.\n",
        $checked,
        $fontsChecked,
    ));

    return 0;
}

/**
 * Every way a vendored font can fail its licensing obligations.
 *
 * Six checks, and each one closes a distinct hole rather than restating the previous:
 *
 *  1. the container must be one this gate can actually read — an unverifiable one is refused by name, and
 *     anything that is neither a readable font nor a permitted companion file is refused too;
 *  2. a REUSE 3.0 `<file>.license` sidecar must exist and declare exactly one SPDX identifier;
 *  3. that identifier must be permissive, or on the narrow font-asset list;
 *  4. the font's own `name` table must corroborate it — this is what makes the sidecar evidence rather
 *     than an assertion — and **every** nameID-13 record must corroborate, not merely one of them;
 *  5. the full licence text must sit beside the binary AND be declared so it ships, and the family must
 *     appear in the notices. OFL-1.1 § 2 requires the licence to accompany the font; Apache-2.0 § 4(a) too;
 *  6. **the inverse direction**: every font path the manifest DECLARES must have been one of the files
 *     visited above. Round 8 found the walk was non-recursive and filtered *in* the extensions it knew, so
 *     an unlicensed `.ttf` one directory down shipped in the release bundle with the gate reporting OK and
 *     the count never moving. This is the direction `spdx-headers.sh` already learned to add — a forward
 *     walk proves the files it found are fine, and says nothing about the files it never reached.
 *
 * @param int $fontsChecked incremented per font read, by reference, so the caller can report the count
 *
 * @return list<string>
 */
function fontViolations(string $directory, string $manifest, string $notices, int &$fontsChecked): array
{
    $violations = [];
    $files = fontTreeFiles($directory);

    if (null === $files) {
        return ['could not read ' . $directory . '.'];
    }

    $acceptable = [...PERMISSIVE, ...PERMISSIVE_FOR_FONT_ASSETS];
    $visited = [];

    foreach ($files as $relative => $path) {
        $entry = $relative;
        $extension = strtolower(pathinfo($entry, \PATHINFO_EXTENSION));

        if (in_array($extension, UNVERIFIABLE_FONT_EXTENSIONS, true)) {
            $violations[] = sprintf(
                '%s is a .%s font, which this gate cannot read a licence out of. Vendor the .ttf or .otf '
                . 'instead, so the binary itself can be cross-checked against its sidecar.',
                $entry,
                $extension,
            );

            continue;
        }

        if (!in_array($extension, FONT_EXTENSIONS, true)) {
            // ALLOWLIST, not an extension filter. A file here that is neither a font this gate can read nor
            // a companion it expects is refused: filtering *in* the known extensions let a
            // `Proprietary-Regular.font` through with no sidecar and no notices row.
            if (!in_array($extension, FONT_DIRECTORY_COMPANION_FILES, true)) {
                $violations[] = sprintf(
                    '%s is neither a font this gate can read (.%s) nor a permitted companion file (.%s). '
                    . 'A file under a font directory that nobody classified is refused, not skipped.',
                    $entry,
                    implode('/.', FONT_EXTENSIONS),
                    implode('/.', FONT_DIRECTORY_COMPANION_FILES),
                );
            }

            continue;
        }

        ++$fontsChecked;
        $visited[$relative] = true;

        $declared = sidecarLicence($path . '.license');

        if (null === $declared) {
            $violations[] = sprintf(
                '%s has no readable %s.license sidecar declaring exactly one SPDX-License-Identifier. '
                . 'A font carries no comment syntax, so REUSE 3.0 puts the tag in a sibling file.',
                $entry,
                $entry,
            );

            continue;
        }

        if (!in_array($declared, $acceptable, true)) {
            $violations[] = sprintf(
                '%s declares %s in its sidecar — not permissive, and not on the font-asset list either.',
                $entry,
                $declared,
            );

            continue;
        }

        // Fail closed on an identifier nobody has checked the wording for, rather than accept the
        // sidecar unverified. The whole value of this check is that it does not trust the sidecar.
        if (!isset(FONT_NAME_TABLE_EVIDENCE[$declared])) {
            $violations[] = sprintf(
                '%s declares %s, which is acceptable, but no name-table wording is recorded for it in '
                . 'FONT_NAME_TABLE_EVIDENCE — so the sidecar cannot be checked against the binary. Read the '
                . "font's nameID 13 and add the expected phrase.",
                $entry,
                $declared,
            );

            continue;
        }

        $embedded = fontLicenceDescription($path);

        if (null === $embedded) {
            $violations[] = sprintf(
                '%s: could not read an OpenType name table out of it, so its sidecar claim of %s is '
                . 'unverifiable. A font this gate cannot parse is refused rather than assumed.',
                $entry,
                $declared,
            );

            continue;
        }

        $expected = FONT_NAME_TABLE_EVIDENCE[$declared];

        // EVERY record must corroborate, not merely one of them. Concatenating the records and running one
        // `str_contains` over the join accepted a font whose Macintosh record read "property of Acme Foundry,
        // all rights reserved, no redistribution" as long as its Windows record mentioned the OFL — a
        // well-formed table, no trickery needed. `sidecarLicence()` already applies "several is as bad as
        // none" to the sidecar; the binary half of the same cross-check must not do the opposite.
        $dissenting = array_values(array_filter(
            $embedded,
            static fn(string $record): bool => !str_contains($record, $expected),
        ));

        if ([] !== $dissenting) {
            $violations[] = sprintf(
                '%s: sidecar says %s, but %d of the font\'s %d name-table licence record(s) do not mention '
                . '"%s" — one reads "%s". The binary wins, and an AMBIGUOUS binary is refused too: fix the '
                . 'sidecar or check where this file came from.',
                $entry,
                $declared,
                count($dissenting),
                count($embedded),
                $expected,
                substr($dissenting[0], 0, 120),
            );

            continue;
        }

        // The family is the part before the first hyphen: NotoSansArabic-Bold.ttf -> NotoSansArabic. That
        // is the convention every font here is named by, and it is what the licence text file is named for.
        $family = explode('-', pathinfo($entry, \PATHINFO_FILENAME))[0];
        $licenceText = $family . '-LICENSE.txt';

        if (!is_file($directory . '/' . $licenceText)) {
            $violations[] = sprintf(
                '%s: %s is missing. Both OFL-1.1 (section 2) and Apache-2.0 (section 4a) '
                . 'require the licence to travel with the files, so the text belongs beside the binary.',
                $entry,
                $licenceText,
            );
        } elseif (!licenceTextIsShipped($manifest, $licenceText)) {
            // BESIDE THE BINARY IS NOT THE SAME AS SHIPPED, and the difference was live: a release web build
            // contained both font families while its generated assets/NOTICES mentioned neither licence,
            // because pubspec's `fonts:` bundles the .ttf and nothing next to it. The repository is not the
            // artifact anyone receives, and the obligation attaches to the artifact.
            $violations[] = sprintf(
                '%s: assets/fonts/%s exists but mobile/pubspec.yaml does not declare it under `assets:`, so '
                . 'it is not in the built bundle. The licence must accompany the files we distribute, not '
                . 'only the ones we commit.',
                $entry,
                $licenceText,
            );
        }

        if (!str_contains($notices, $family)) {
            $violations[] = sprintf(
                '%s: the %s family does not appear in %s. A vendored font is a distributed third-party '
                . 'work and licensing invariant 8(a) applies to it exactly as to a package.',
                $entry,
                $family,
                ltrim(NOTICES, '/'),
            );
        }
    }

    // THE FRAMEWORK'S OWN FONTS. Not in the repository, not in any lock file, and still distributed — so the
    // only thing that can be checked is that we discharge the obligation: the licence text must exist and must
    // be declared so it ships. `uses-material-design: true` put MaterialIcons-Regular.otf in the bundle with
    // no licence anywhere in the artifact for two commits, and no walk over our own directories could see it.
    foreach (FRAMEWORK_PROVIDED_FONTS as $font => $record) {
        if (!is_file($directory . '/' . $record['text'])) {
            $violations[] = sprintf(
                '%s is injected into the bundle by `%s` and is %s, but %s is not in the font directory. Its '
                . 'attribution, licence URI and statement of modification have to travel with the artifact.',
                $font,
                $record['source'],
                $record['obligation'],
                $record['text'],
            );

            continue;
        }

        if (!licenceTextIsShipped($manifest, $record['text'])) {
            $violations[] = sprintf(
                '%s: %s exists but is not declared under `flutter:` -> `assets:` in %s, so it is committed '
                . 'and not shipped — which is the distinction that made this a finding in the first place.',
                $font,
                $record['text'],
                basename($manifest),
            );
        }
    }

    // THE INVERSE DIRECTION. Everything above proves the files this walk FOUND are licensed; it says nothing
    // about a font the walk never reached. So ask the manifest what it declares, and require every one of
    // those paths to have been visited. A `.ttf` in a subdirectory, or under an extension the walk did not
    // recognise, shipped in the release bundle with the gate green until this existed.
    foreach (declaredFontAssets($manifest) as $declaredPath) {
        $relative = preg_replace('#^assets/fonts/#', '', $declaredPath);

        if (!isset($visited[$relative])) {
            $violations[] = sprintf(
                '%s is declared in %s but was never examined by this walk — so nothing verified its licence, '
                . 'and it ships anyway. Either it is missing from disk, or it is a container this gate does '
                . 'not read.',
                $declaredPath,
                basename($manifest),
            );
        }
    }

    return $violations;
}

/**
 * Every regular file under a font directory, recursively, keyed by its path relative to that directory.
 *
 * Recursive because the first version was not, and a `.ttf` one level down was invisible to the gate while
 * shipping in the release bundle. Sidecars are excluded from the returned set: they are read by name off the
 * font they describe, and returning them would have the walk classify each one as an unexpected companion.
 *
 * @return array<string, string>|null null when the directory cannot be read
 */
function fontTreeFiles(string $directory): ?array
{
    if (!is_dir($directory)) {
        return null;
    }

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY,
    );

    $files = [];

    foreach ($iterator as $file) {
        if (!$file instanceof \SplFileInfo || !$file->isFile()) {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($directory) + 1);

        if (str_ends_with($relative, '.license')) {
            continue;
        }

        $files[$relative] = $file->getPathname();
    }

    ksort($files);

    return $files;
}

/**
 * The font asset paths a Flutter manifest declares under `fonts:`.
 *
 * Read with a small line reader for the same reason `pubDirectRequirements()` is: these gates run on plain
 * PHP with nothing installed. The pattern deliberately tolerates a trailing comment — `- asset: x.ttf  # y`
 * is legal YAML, and an end-anchored pattern that silently matched nothing is exactly the defect round 8
 * found in the Dart-side equivalent of this parse.
 *
 * @return list<string>
 */
function declaredFontAssets(string $manifest): array
{
    if (!is_file($manifest)) {
        return [];
    }

    $raw = file_get_contents($manifest);

    if (false === $raw) {
        return [];
    }

    if (!preg_match_all('/^\s+-\s+asset:\s*(\S+)/m', $raw, $matches)) {
        return [];
    }

    return array_values(array_unique($matches[1]));
}

/**
 * Whether a Flutter manifest declares a font licence text under `flutter:` → `assets:`, so that it ships.
 *
 * SCOPED TO THAT BLOCK, which the first version was not. A bare whole-line pattern is satisfied by any
 * list-item-shaped line anywhere in the file, so moving the `assets:` block out of `flutter:` — an easy and
 * entirely realistic YAML mistake — left five fonts shipping with **zero** licence texts and the gate green.
 * A path inside a folded scalar under `description:` satisfied it too. Both are now refused, because the
 * question is not "is this string in the file" but "will Flutter bundle this file".
 *
 * The manifest is a parameter rather than a constant so each tier is validated against its own manifest: the
 * caller iterates FONT_ASSET_DIRECTORIES, and a hardcoded path would check a second tier's asset against the
 * Flutter one.
 */
function licenceTextIsShipped(string $manifest, string $licenceText): bool
{
    if (!is_file($manifest)) {
        return false;
    }

    $raw = file_get_contents($manifest);

    if (false === $raw) {
        return false;
    }

    $wanted = 'assets/fonts/' . $licenceText;
    $inFlutter = false;
    $inAssets = false;

    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/^flutter:\s*$/', $line)) {
            $inFlutter = true;
            $inAssets = false;

            continue;
        }

        // Any other top-level key ends the flutter: block. Checked before the entry match so that an
        // `assets:` key at the file root cannot be mistaken for the one inside it.
        if (preg_match('/^[a-zA-Z_]/', $line)) {
            $inFlutter = false;
            $inAssets = false;

            continue;
        }

        if (!$inFlutter) {
            continue;
        }

        if (preg_match('/^\s+assets:\s*$/', $line)) {
            $inAssets = true;

            continue;
        }

        // A sibling key at the same indentation as `assets:` (fonts:, uses-material-design:) closes it.
        if ($inAssets && preg_match('/^\s+[a-zA-Z_][a-zA-Z0-9_-]*:/', $line)) {
            $inAssets = false;

            continue;
        }

        if ($inAssets && preg_match('/^\s+-\s+(\S+)/', $line, $matches) && $matches[1] === $wanted) {
            return true;
        }
    }

    return false;
}

/**
 * The single SPDX identifier a REUSE sidecar declares, or null if it declares none or several.
 *
 * Several is as bad as none: two tags mean the file's licensing is ambiguous, and this gate must not pick
 * whichever one happens to pass.
 */
function sidecarLicence(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }

    $raw = file_get_contents($path);

    if (false === $raw) {
        return null;
    }

    if (!preg_match_all('/^SPDX-License-Identifier:\s*(\S+)\s*$/m', $raw, $matches)) {
        return null;
    }

    $identifiers = array_values(array_unique($matches[1]));

    return 1 === count($identifiers) ? $identifiers[0] : null;
}

/**
 * nameID 13 — `License Description` — out of an sfnt font's `name` table.
 *
 * Written by hand rather than with a font library for the reason every gate here is: these run on plain PHP
 * with nothing installed, which is what makes them work in this container at all (see § Gotchas on GitHub
 * egress). The parse is deliberately strict and returns null rather than guessing — the caller treats null
 * as a failure, so a container shape this does not understand is refused instead of approved.
 *
 * Returns the records SEPARATELY rather than joined. The join was the bug: the caller ran one
 * `str_contains` over it, so a font with two nameID-13 records passed when only one of them corroborated the
 * sidecar — while the other said the font was proprietary and could not be redistributed.
 *
 * @return list<string>|null null when the file cannot be parsed, which the caller treats as a REFUSAL
 */
function fontLicenceDescription(string $path): ?array
{
    // The size ceiling comes first, and before file_get_contents(), because the point is not to load the file
    // and then object: a 100 MB .ttf aborted this gate with a PHP fatal and a stack trace under a normal
    // memory_limit. That failed closed, but a crash and a detection are indistinguishable in output.
    $size = filesize($path);

    if (false === $size || $size > MAX_FONT_BYTES) {
        return null;
    }

    $raw = file_get_contents($path);

    if (false === $raw || strlen($raw) < 12) {
        return null;
    }

    $header = unpack('Nversion/nnumTables', substr($raw, 0, 8));

    if (false === $header) {
        return null;
    }

    // 0x00010000 is TrueType outlines; 'OTTO' is CFF. A 'ttcf' collection deliberately falls through to
    // null: it holds several fonts and this parser addresses one.
    if (0x00010000 !== $header['version'] && 'OTTO' !== substr($raw, 0, 4)) {
        return null;
    }

    $nameOffset = null;
    $nameLength = 0;

    for ($i = 0; $i < $header['numTables']; ++$i) {
        $record = 12 + 16 * $i;

        if (strlen($raw) < $record + 16) {
            return null;
        }

        if ('name' !== substr($raw, $record, 4)) {
            continue;
        }

        $entry = unpack('Noffset/Nlength', substr($raw, $record + 8, 8));

        if (false === $entry) {
            return null;
        }

        $nameOffset = $entry['offset'];
        $nameLength = $entry['length'];

        break;
    }

    if (null === $nameOffset || strlen($raw) < $nameOffset + 6) {
        return null;
    }

    // CLAMP THE DECLARED LENGTH TO THE FILE. $nameLength arrives straight out of the table directory, which
    // is the font's own bytes, and the string-bounds check below is the only thing keeping the reads inside
    // the name table. Declaring nameLength = 0xFFFFFFFF made that check vacuous, and a nameID-13 record could
    // then point ~128 KB past the table into another table's bytes: a phrase planted in the `glyf` region
    // satisfied the cross-check on a font whose real licence record said "Apache License". PHP's substr()
    // clips at EOF so nothing crashed — it simply believed the wrong bytes. Refuse rather than clamp
    // silently: a font whose directory lies about its own table is not one to interpret charitably.
    if ($nameOffset + $nameLength > strlen($raw)) {
        return null;
    }

    $table = unpack('nformat/ncount/nstringOffset', substr($raw, $nameOffset, 6));

    if (false === $table) {
        return null;
    }

    $descriptions = [];

    for ($i = 0; $i < $table['count']; ++$i) {
        $record = $nameOffset + 6 + 12 * $i;

        if (strlen($raw) < $record + 12) {
            return null;
        }

        $name = unpack(
            'nplatformId/nencodingId/nlanguageId/nnameId/nlength/noffset',
            substr($raw, $record, 12),
        );

        if (false === $name || 13 !== $name['nameId']) {
            continue;
        }

        $start = $nameOffset + $table['stringOffset'] + $name['offset'];

        if ($start + $name['length'] > $nameOffset + $nameLength) {
            return null;
        }

        $value = substr($raw, $start, $name['length']);

        // Platform 3 (Windows) and platform 0 (Unicode) store UTF-16BE; platform 1 (Macintosh) stores a
        // single-byte encoding. Nothing here needs the exact Mac code page — the comparison is an ASCII
        // substring — so latin-1 is a safe reading of it.
        $descriptions[] = in_array($name['platformId'], [0, 3], true)
            ? (string) mb_convert_encoding($value, 'UTF-8', 'UTF-16BE')
            : $value;
    }

    return [] === $descriptions ? null : $descriptions;
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

    // And the Flutter tier. This is the ONLY check available for it — see OWED for why — which makes it
    // more load-bearing here than elsewhere, not less: a pub dependency added without a notices row is
    // otherwise entirely unexamined.
    $pubNames = pubDirectRequirements(REPO_ROOT . '/mobile/pubspec.yaml');

    if ([] !== $pubNames) {
        $tiers['Flutter client'] = $pubNames;
    }

    return $tiers;
}

/**
 * The direct dependencies a pubspec.yaml declares.
 *
 * Parsed with a deliberately small YAML reader rather than a library, because the domain layer's
 * zero-dependency rule does not apply to gate scripts but the *installability* rule does: these gates run
 * on plain PHP with nothing installed, which is what makes them work in this container at all.
 *
 * The shape being read is fixed and shallow — `dependencies:` and `dev_dependencies:` each followed by
 * two-space-indented keys — so a full parser would buy nothing. An SDK dependency (`flutter: {sdk: flutter}`)
 * still counts: it is a dependency with a licence, and it belongs in the notices.
 *
 * @return list<string>
 */
function pubDirectRequirements(string $manifestPath): array
{
    if (!is_file($manifestPath)) {
        return [];
    }

    $raw = file_get_contents($manifestPath);

    if (false === $raw) {
        return [];
    }

    $names = [];
    $inSection = false;

    foreach (explode("\n", $raw) as $line) {
        if (preg_match('/^(dependencies|dev_dependencies):\s*$/', $line)) {
            $inSection = true;

            continue;
        }

        // Any other top-level key ends the section. Checked BEFORE the entry match, or `flutter:` at the
        // top level (the SDK config block) would be read as a dependency of the preceding section.
        if (preg_match('/^[a-zA-Z_]/', $line)) {
            $inSection = false;

            continue;
        }

        if ($inSection && preg_match('/^  ([a-z_][a-z_0-9]*):/', $line, $matches)) {
            $names[] = $matches[1];
        }
    }

    return array_values(array_unique($names));
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
                is_string($licence) => [$licence],
                default => $licence,
            },
            'dev' => true === ($package['dev'] ?? false),
        ];
    }

    return $packages;
}
