<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

/**
 * Shared by the licence gate and the notices generator: ONE reading of the two lock files, so the gate
 * checks exactly what the notices record.
 *
 * A dependency record is ['tier' => 'api'|'web', 'name', 'version', 'licence' (SPDX string or ''), 'dev' => bool].
 *
 * @return list<array{tier: string, name: string, version: string, licence: string, dev: bool}>
 */
function dependencyInventory(string $root): array
{
    $records = [];

    $composer = readJson($root.'/api/composer.lock');
    foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
        foreach ($composer[$section] ?? [] as $package) {
            $licence = $package['license'] ?? [];
            $records[] = [
                'tier' => 'api',
                'name' => (string) $package['name'],
                'version' => ltrim((string) $package['version'], 'v'),
                'licence' => implode(' AND ', array_map('strval', (array) $licence)),
                'dev' => $dev,
            ];
        }
    }

    $npm = readJson($root.'/web/package-lock.json');
    $seen = [];
    foreach ($npm['packages'] ?? [] as $path => $package) {
        if ('' === $path) {
            continue; // the workspace itself
        }
        $name = substr((string) $path, (int) strrpos('/'.$path, 'node_modules/') + strlen('node_modules/') - 1);
        $licence = $package['license'] ?? '';
        if (is_array($licence)) {
            $licence = (string) ($licence['type'] ?? '');
        }
        $dev = (bool) ($package['dev'] ?? false);
        $key = $name.'@'.$package['version'];
        // The same package can be nested several times; one row per name and version, runtime winning over dev.
        if (isset($seen[$key]) && !$dev) {
            $records[$seen[$key]]['dev'] = false;
        }
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = count($records);
        $records[] = ['tier' => 'web', 'name' => $name, 'version' => (string) $package['version'], 'licence' => (string) $licence, 'dev' => $dev];
    }

    // A declared override takes the place of the lock's word only while the lock still says what it was read against.
    foreach (licenceOverrides($root) as $override) {
        foreach ($records as $i => $r) {
            if ($r['tier'] === $override['tier'] && $r['name'] === $override['name'] && $r['licence'] === $override['lock']) {
                $records[$i]['licence'] = $override['licence'];
            }
        }
    }

    usort($records, static fn (array $a, array $b): int => [$a['tier'], $a['name'], $a['version']] <=> [$b['tier'], $b['name'], $b['version']]);

    return $records;
}

/**
 * A package whose lock entry names one licence while it ships another text too (libphonenumber-js: its code MIT, the
 * metadata it derives from Google's libphonenumber Apache-2.0). Each is declared in scripts/notices/licence-overrides.json
 * with what its lock said when it was read, the whole expression, the text it ships (kept under scripts/notices/texts/)
 * and why; the gate refuses an override whose lock moved, whose package left, or that lacks its text or its reason.
 *
 * @return list<array{tier: string, name: string, lock: string, licence: string, text: string, reason: string}>
 */
function licenceOverrides(string $root): array
{
    $path = $root.'/scripts/notices/licence-overrides.json';
    if (!is_file($path)) {
        return [];
    }
    $overrides = [];
    foreach (readJson($path) as $o) {
        $o = (array) $o;
        $overrides[] = [
            'tier' => (string) ($o['tier'] ?? ''),
            'name' => (string) ($o['name'] ?? ''),
            'lock' => (string) ($o['lock'] ?? ''),
            'licence' => (string) ($o['licence'] ?? ''),
            'text' => (string) ($o['text'] ?? ''),
            'reason' => trim((string) ($o['reason'] ?? '')),
        ];
    }

    return $overrides;
}

/**
 * What is wrong with the declared overrides against the locks as they are.
 *
 * @return list<string>
 */
function licenceOverrideProblems(string $root): array
{
    $locked = [];
    foreach (dependencyInventory($root) as $r) {
        $locked[$r['tier'].'/'.$r['name']] = $r['licence'];
    }
    $problems = [];
    foreach (licenceOverrides($root) as $o) {
        $label = $o['tier'].'/'.$o['name'];
        if (!array_key_exists($label, $locked)) {
            $problems[] = sprintf('scripts/notices/licence-overrides.json names %s, which no lock holds', $label);
        } elseif ($locked[$label] !== $o['licence']) {
            $problems[] = sprintf("scripts/notices/licence-overrides.json read %s's lock as '%s', it now says '%s': read the package's licence files again", $label, $o['lock'], $locked[$label]);
        }
        if ('' === $o['reason']) {
            $problems[] = sprintf('scripts/notices/licence-overrides.json gives no reason for %s', $label);
        }
        $text = $root.'/'.$o['text'];
        if ('' === $o['text'] || !is_file($text) || '' === trim((string) file_get_contents($text))) {
            $problems[] = sprintf('%s, the licence text %s ships, is missing or empty', $o['text'], $label);
        }
    }

    return $problems;
}

/**
 * The overrides with their texts, as the notices print them.
 *
 * @return list<array{tier: string, name: string, lock: string, licence: string, text: string, reason: string, body: string}>
 */
function licenceOverrideTexts(string $root): array
{
    return array_map(static function (array $o) use ($root): array {
        $path = $root.'/'.$o['text'];
        $o['body'] = is_file($path) ? rtrim((string) file_get_contents($path))."\n" : '';

        return $o;
    }, licenceOverrides($root));
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException(sprintf('%s is missing', $path));
    }
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('%s is not a JSON object', $path));
    }

    return $decoded;
}

/**
 * Vendored font FILES under web/public and web/src (not packages): one record per directory holding a font file,
 * with the licence identified from the LICENSE text beside the files. The licence is '' when there is no LICENSE
 * and 'unknown' when its text names no licence recognised here; the gate refuses both, the notices list every one.
 *
 * @return list<array{dir: string, files: list<string>, licence: string}>
 */
function vendoredFonts(string $root): array
{
    $byDir = [];
    foreach (['web/public', 'web/src'] as $base) {
        if (!is_dir($root.'/'.$base)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$base, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && 1 === preg_match('/\.(woff2?|ttf|otf)$/i', $file->getFilename())) {
                $byDir[substr($file->getPath(), strlen($root) + 1)][] = $file->getFilename();
            }
        }
    }
    ksort($byDir);
    $byDir = withoutIgnored($root, $byDir);

    $fonts = [];
    foreach ($byDir as $dir => $names) {
        sort($names);
        $fonts[] = ['dir' => (string) $dir, 'files' => $names, 'licence' => fontLicence($root.'/'.$dir.'/LICENSE')];
    }

    return $fonts;
}

/**
 * Leaves out the font files git ignores: a font the build generates from a locked package (the icon font cut to the
 * declared icons, web/scripts/subset-icons.mjs) is that package, whose licence the lock records, not a vendored font.
 * A font not yet staged is still checked. Outside a git work tree nothing is left out.
 *
 * @param array<string, list<string>> $byDir file names by directory relative to the root
 *
 * @return array<string, list<string>>
 */
function withoutIgnored(string $root, array $byDir): array
{
    $paths = [];
    foreach ($byDir as $dir => $names) {
        foreach ($names as $name) {
            $paths[] = $dir.'/'.$name;
        }
    }
    if ([] === $paths) {
        return $byDir;
    }
    $process = proc_open(['git', '-C', $root, 'check-ignore', '--stdin'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return $byDir;
    }
    fwrite($pipes[0], implode("\n", $paths)."\n");
    fclose($pipes[0]);
    $ignored = array_filter(explode("\n", (string) stream_get_contents($pipes[1])));
    fclose($pipes[1]);
    fclose($pipes[2]);
    // 0: some are ignored; 1: none is; anything else (128, not a repository): nothing to leave out.
    if (0 !== proc_close($process)) {
        return $byDir;
    }
    $kept = [];
    foreach ($byDir as $dir => $names) {
        $left = array_values(array_filter($names, static fn (string $name): bool => !in_array($dir.'/'.$name, $ignored, true)));
        if ([] !== $left) {
            $kept[$dir] = $left;
        }
    }

    return $kept;
}

/**
 * What vendored WebAssembly compiles in, which no lock file shows (docs/SPEC.md § 7, 2026-09-22 22:38): one
 * `COMPONENTS.json` per directory under web/src/third-party, beside the licence texts it names. Each is the audit of ONE
 * tarball — the package, its version and its lock integrity — and lists every component with its SPDX licence, its
 * copyright line and the file holding its licence text. The build copies the directory next to the wasm it describes.
 *
 * @return list<array{dir: string, package: string, version: string, integrity: string, file: string, components: list<array{name: string, licence: string, copyright: string, text: string}>}>
 */
function vendoredWasm(string $root): array
{
    $manifests = glob($root.'/web/src/third-party/*/COMPONENTS.json') ?: [];
    sort($manifests);
    $wasm = [];
    foreach ($manifests as $path) {
        $j = readJson($path);
        $components = [];
        foreach ((array) ($j['components'] ?? []) as $c) {
            $c = (array) $c;
            $components[] = [
                'name' => (string) ($c['name'] ?? ''),
                'licence' => (string) ($c['licence'] ?? ''),
                'copyright' => (string) ($c['copyright'] ?? ''),
                'text' => (string) ($c['text'] ?? ''),
            ];
        }
        $wasm[] = [
            'dir' => substr(dirname($path), strlen($root) + 1),
            'package' => (string) ($j['package'] ?? ''),
            'version' => (string) ($j['version'] ?? ''),
            'integrity' => (string) ($j['integrity'] ?? ''),
            'file' => (string) ($j['file'] ?? ''),
            'components' => $components,
        ];
    }

    return $wasm;
}

/**
 * The npm packages whose WebAssembly the web build copies into what it ships: every `assets` entry of web/angular.json
 * whose input lies in node_modules and whose glob names a `.wasm`.
 *
 * @return list<string>
 */
function shippedWasmPackages(string $root): array
{
    $path = $root.'/web/angular.json';
    if (!is_file($path)) {
        return [];
    }
    $packages = [];
    $walk = static function (mixed $node) use (&$walk, &$packages): void {
        if (!is_array($node)) {
            return;
        }
        $input = $node['input'] ?? null;
        $glob = $node['glob'] ?? null;
        if (is_string($input) && is_string($glob) && str_contains($glob, '.wasm')
            && 1 === preg_match('#node_modules/((?:@[^/]+/)?[^/]+)#', $input, $m)) {
            $packages[] = $m[1];
        }
        foreach ($node as $child) {
            $walk($child);
        }
    };
    $walk(readJson($path));
    $packages = array_values(array_unique($packages));
    sort($packages);

    return $packages;
}

/** The SPDX identifier a font LICENSE file carries, recognised from its text; the SIL wording is the only one known. */
function fontLicence(string $path): string
{
    if (!is_file($path)) {
        return '';
    }

    return 1 === preg_match('/SIL Open Font License,?\s+Version 1\.1/i', (string) file_get_contents($path)) ? 'OFL-1.1' : 'unknown';
}

/**
 * The notices document, rendered deterministically from the inventory and the vendored fonts. The gate compares the
 * file on disk to this.
 *
 * @param list<array{tier: string, name: string, version: string, licence: string, dev: bool}> $records
 * @param list<array{dir: string, files: list<string>, licence: string}>                     $fonts
 * @param list<array{dir: string, package: string, version: string, integrity: string, file: string, components: list<array{name: string, licence: string, copyright: string, text: string}>}> $wasm
 */
function renderNotices(array $records, array $fonts = [], array $wasm = [], array $overrides = []): string
{
    $out = "# Third-party notices\n\n";
    $out .= "<!-- GENERATED by scripts/notices/generate-third-party-notices.php from api/composer.lock, web/package-lock.json, the vendored font files and the vendored WebAssembly manifests. Do not edit by hand: regenerate, and the licence gate checks it is current. -->\n\n";
    $out .= "twes-in is AGPL-3.0-or-later with a commercial licence (see `LICENSING.md`). Every package below is\n";
    $out .= "permissive under the policy in that file, as enforced by `scripts/gates/dependency-licences.php`.\n";
    $out .= "Base images and service containers (PostgreSQL, nginx, Gotenberg, Mailpit, FrankenPHP), and the programs the\n";
    $out .= "backup image installs (pgBackRest, MIT; restic, BSD-2-Clause; supercronic, MIT; curl, curl; see `docs/UPDATE.md`), are\n";
    $out .= "aggregated, not compiled into what we distribute, and are not listed here.\n";

    foreach (['api' => 'api (Composer)', 'web' => 'web (npm)'] as $tier => $title) {
        $rows = array_values(array_filter($records, static fn (array $r): bool => $r['tier'] === $tier));
        $runtime = count(array_filter($rows, static fn (array $r): bool => !$r['dev']));
        $out .= sprintf("\n## %s — %d runtime, %d dev\n\n| Package | Version | Licence | Role |\n|---|---|---|---|\n", $title, $runtime, count($rows) - $runtime);
        foreach ($rows as $r) {
            $out .= sprintf("| %s | %s | %s | %s |\n", $r['name'], $r['version'], '' === $r['licence'] ? '(none declared)' : $r['licence'], $r['dev'] ? 'dev' : 'runtime');
        }
    }

    if ([] !== $fonts) {
        $out .= sprintf("\n## Vendored fonts — %d\n\nShipped unmodified, each with its licence text in the same directory.\n\n| Directory | Licence | Files |\n|---|---|---|\n", count($fonts));
        foreach ($fonts as $f) {
            $out .= sprintf("| %s | %s | %s |\n", $f['dir'], '' === $f['licence'] ? '(no LICENSE)' : $f['licence'], implode(', ', $f['files']));
        }
    }

    if ([] !== $overrides) {
        $out .= sprintf("\n## Licences beyond the lock — %d\n\nA package whose lock entry names one licence while it ships another text too, as declared in\n`scripts/notices/licence-overrides.json`; the tables above give its whole licence.\n\n| Package | Its lock says | Licence | Why |\n|---|---|---|---|\n", count($overrides));
        foreach ($overrides as $o) {
            $out .= sprintf("| %s/%s | %s | %s | %s |\n", $o['tier'], $o['name'], $o['lock'], $o['licence'], $o['reason']);
        }
        foreach ($overrides as $o) {
            $out .= sprintf("\n### %s/%s — `%s`\n\n```text\n%s```\n", $o['tier'], $o['name'], $o['text'], $o['body']);
        }
    }

    foreach ($wasm as $w) {
        $out .= sprintf("\n## Compiled into %s %s — %s\n\nThe package's lock entry names its own licence only. What its WebAssembly compiles in, as audited from that tarball\n(`%s/COMPONENTS.json`); each licence text ships beside the wasm.\n\n| Component | Licence | Copyright | Text |\n|---|---|---|---|\n", $w['package'], $w['version'], $w['file'], $w['dir']);
        foreach ($w['components'] as $c) {
            $out .= sprintf("| %s | %s | %s | %s |\n", $c['name'], $c['licence'], $c['copyright'], $c['text']);
        }
    }

    return $out;
}

/** @return array{root: string, flags: list<string>} */
function parseArguments(array $argv): array
{
    $root = dirname(__DIR__, 2);
    $flags = [];
    for ($i = 1; $i < count($argv); ++$i) {
        if ('--root' === $argv[$i] && isset($argv[$i + 1])) {
            $root = rtrim($argv[++$i], '/');
            continue;
        }
        $flags[] = (string) $argv[$i];
    }

    return ['root' => $root, 'flags' => $flags];
}
