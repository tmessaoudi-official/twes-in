<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

/*
 * The driver for `worker-mode-blocked.sh`'s analysis. Reads a JSON request on stdin so nothing has to be escaped
 * through a shell argument list -- the previous version passed rule sets as words and a mutation harness was fooled
 * once already by shell escaping that silently did not apply.
 *
 * Prints one violation per line and exits 0; the caller owns the verdict. Exiting 0 on findings is deliberate: a
 * non-zero exit here would be indistinguishable from a crash, and this repository has confused those twice.
 */

require __DIR__ . '/worker-mode-analyse.php';

$request = json_decode((string) file_get_contents('php://stdin'), true);

if (!is_array($request)) {
    fwrite(STDERR, "worker-mode-run: the request on stdin is not a JSON object.\n");
    exit(2);
}

$analysis = new WorkerModeAnalysis(
    $request['permitted_runtime'],
    $request['permitted_runtime_lines'],
    $request['permitted_seam_lines'],
    $request['seams'],
    $request['structural_placeholder'],
);

$violations = [];
$inspected = 0;
$scanned = 0;

foreach ($request['files'] as $relative) {
    $path = $request['root'] . '/' . $relative;
    $contents = @file_get_contents($path);

    if (false === $contents) {
        // AN UNREADABLE IN-SCOPE FILE IS A VIOLATION, not a skip. The whole redesign is about fail-closed polarity,
        // and "could not look" is the one answer a security gate must never treat as "nothing to see".
        $violations[] = sprintf('%s: is in scope and could not be read, so nothing about it was verified.', $relative);
        ++$inspected;
        continue;
    }

    ++$inspected;

    // The fast reject, kept because the inverted scope reads every tracked file and the cost is linear in bytes.
    // It is a CONTENT test on the same bytes the analysis would read -- and unlike the bash version it cannot miss
    // an encoding `grep` does not understand, because it is the same `strpos` the rules use.
    $interesting = false;
    foreach ($analysis->keywords() as $keyword) {
        if (str_contains($contents, $keyword)) {
            $interesting = true;
            break;
        }
    }

    // A CONTINUATION CAN SPLIT A KEYWORD, so a plain substring test is not sufficient on its own: `ENV APP_RUN\`
    // followed by `TIME=...` contains neither keyword anywhere in the raw bytes. Any file carrying a continuation
    // at all is therefore analysed. That is the fix for the defect that defeated the previous fast reject.
    if (!$interesting && preg_match('/\\\\\r?\n/', $contents)) {
        $interesting = true;
    }

    if (in_array($relative, $request['caddyfiles'], true)) {
        $interesting = true;                    // Caddyfile rules match no keyword
    }

    if ($interesting) {
        ++$scanned;
        $violations = [...$violations, ...$analysis->violationsFor($relative, $contents)];
    }

    // EVERY TRACKED JSON FILE, not just files NAMED `composer.json`. The filename scoping was an inclusion
    // enumeration and a certification round walked through it: `ENV COMPOSER=composer-worker.json` makes ANY file
    // the root package (Composer's `Factory::getComposerFile()` honours `$_ENV['COMPOSER']`), so a tracked
    // `api/composer-worker.json` carrying `extra.runtime` reached the OWNER database role with every gate green.
    // Checking any JSON that HAS the key costs nothing on a file that does not.
    if (str_ends_with($relative, '.json')) {
        $violations = [...$violations, ...composerRuntimeViolations($relative, $contents, $request['permitted_runtime'])];
    }

    if (in_array($relative, $request['caddyfiles'], true)) {
        $violations = [...$violations, ...caddyViolations($relative, $contents, $analysis)];
    }
}

// EVERY IDENTIFIED CADDY CONFIG IS READ, even one the SCOPE excludes. The loop above only reaches files in
// `in_scope`, while the caddyfiles set comes from an UNFILTERED derivation -- so a served `infra/api/tests/Caddyfile`
// was COUNTED (`caddyfiles=2`, its placeholders even feeding the seam set) and never READ, with `.dockerignore`
// excluding `api/tests` but not `infra/api/tests`. Two rounds recorded that FIXED while it reproduced. A config the
// gate itself calls a config is in scope for the Caddy rules by definition: the exclusion list is about where
// CONFIGURATION lives, not about what the server is handed.
foreach ($request['caddyfiles'] as $servedRelative) {
    if (in_array($servedRelative, $request['files'], true)) {
        continue;                               // already read by the loop above
    }

    $servedContents = @file_get_contents($request['root'] . '/' . $servedRelative);

    if (false === $servedContents) {
        $violations[] = sprintf(
            '%s: is served as a Caddy config and could not be read, so nothing about it was verified.',
            $servedRelative,
        );
        continue;
    }

    ++$inspected;
    ++$scanned;
    $violations = [...$violations, ...caddyViolations($servedRelative, $servedContents, $analysis)];
}

echo json_encode([
    'violations' => $violations,
    'inspected' => $inspected,
    'scanned' => $scanned,
    'declarations' => $analysis->approvedDeclarations(),
], JSON_THROW_ON_ERROR), "\n";

/**
 * `extra.runtime` must be ABSENT or hold nothing but `class` equal to the permitted runtime -- an allow-list of
 * KEYS, because `symfony/runtime`'s ComposerPlugin consumes `class`, `autoload_template` and `project_dir` and then
 * BAKES every remaining key into the generated bootstrap as runtime constructor options.
 *
 * @return list<string>
 */
function composerRuntimeViolations(string $relative, string $contents, string $permittedRuntime): array
{
    // A FILE THAT CANNOT CARRY THE KEY HAS NOTHING TO RULE OUT. Widening the scope from files NAMED
    // `composer.json` to every tracked `.json` immediately produced six false positives:
    // `admin/.vscode/*.json` and `admin/tsconfig*.json` are JSONC -- JSON WITH COMMENTS, which is what VS
    // Code and the TypeScript compiler actually consume -- so `json_decode` fails on them by design.
    //
    // The unparseable-is-a-violation rule is KEPT and narrowed to files that MENTION `runtime`: if a file
    // could carry the key and cannot be parsed, it has not been ruled out. A JSONC file can never be a
    // Composer root package (Composer requires strict JSON), so refusing to refuse one that never mentions
    // `runtime` concedes nothing.
    if (!str_contains($contents, '"runtime"')) {
        return [];
    }

    $data = json_decode($contents, true);

    if (!is_array($data)) {
        return [sprintf(
            '%s: could not be parsed as JSON, so extra.runtime could not be ruled out — an unverifiable file is a '
            . 'violation, not a pass.',
            $relative,
        )];
    }

    $extra = $data['extra']['runtime'] ?? null;

    if (null === $extra) {
        return [];
    }

    if (!is_array($extra)) {
        return [sprintf('%s: extra.runtime is not an object.', $relative)];
    }

    $consumed = [
        'autoload_template' => 'REPLACES vendor/autoload_runtime.php wholesale, so the runtime class is hardcoded '
            . 'in your template and APP_RUNTIME need not appear anywhere in the tree',
        'project_dir' => 'consumed by the ComposerPlugin; refused only because nothing here needs it, and an '
            . 'allow-list stays minimal until something does',
    ];

    $offending = [];

    foreach ($extra as $key => $value) {
        if ('class' === $key) {
            if ($value !== $permittedRuntime) {
                $offending[] = sprintf('class=%s', is_scalar($value) ? (string) $value : gettype($value));
            }
            continue;
        }
        $offending[] = sprintf(
            '%s (%s)',
            $key,
            $consumed[$key] ?? 'baked into vendor/autoload_runtime.php as a runtime constructor option',
        );
    }

    if ([] === $offending) {
        return [];
    }

    return [sprintf(
        '%s: extra.runtime carries %s; the ONLY permitted content is class="%s", because symfony/runtime bakes '
        . 'every other key into the generated bootstrap.',
        $relative,
        implode('; ', $offending),
        $permittedRuntime,
    )];
}

/**
 * An active `worker` directive and any `import` are refused in a Caddyfile. Both are checked on the SCANNED code so
 * a documented worker block stays legal as a comment -- deleting the documentation is not an acceptable fix.
 *
 * @return list<string>
 */
function caddyViolations(string $relative, string $contents, WorkerModeAnalysis $analysis): array
{
    $violations = [];

    foreach ($analysis->logicalLines($contents) as $logical) {
        $code = $analysis->scan($logical['line'])['code'];
        $trimmed = trim($code);

        if ('' === $trimmed) {
            continue;
        }

        if (preg_match('/(^|[\s{])worker([\s{]|$)/i', $trimmed)) {
            $violations[] = sprintf(
                '%s:%d: an ACTIVE `worker` directive — keep the documented block commented.',
                $relative,
                $logical['at'],
            );
        }

        if (preg_match('/^import\s/', $trimmed)) {
            $violations[] = sprintf(
                '%s:%d: `%s` — an imported Caddy config is outside this sweep, so an `import` is refused while '
                . 'worker mode is blocked.',
                $relative,
                $logical['at'],
                $trimmed,
            );
        }
    }

    return $violations;
}
