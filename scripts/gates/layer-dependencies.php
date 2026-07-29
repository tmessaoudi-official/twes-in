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
const SRC = REPO_ROOT . '/api/src';

/**
 * layer => namespaces it may NOT reference.
 *
 * Read as "inward only": Domain is innermost, UI and Infrastructure outermost.
 */
const FORBIDDEN_BY_LAYER = [
    'Domain' => ['Twes\\Application', 'Twes\\Infrastructure', 'Twes\\UI'],
    'Application' => ['Twes\\Infrastructure', 'Twes\\UI'],
];

/**
 * Non-PHP-built-in namespaces the domain layer is allowed to reference.
 *
 * Deliberately empty. The domain uses PHP's own types and bcmath's plain functions and nothing else —
 * see api/src/Domain/Shared/Decimal.php for why exact arithmetic needs no package here. An entry in
 * this list is a decision to be argued in a commit message, not a convenience.
 */
const DOMAIN_VENDOR_ALLOWLIST = [];

/*
 * Note on what counts as "third-party": a reference with no backslash left after the leading one is a
 * GLOBAL symbol — `\LogicException`, `\sprintf`, `\DateTimeImmutable`, `\DivisionByZeroError`. Those are
 * PHP itself, and the domain uses them freely. Only a genuinely namespaced reference can be a package.
 *
 * The residual gap, stated rather than hidden: a Composer package that publishes a class into the
 * global namespace would not be caught here. That is rare enough, and the false-positive rate of the
 * alternative high enough, that this is the deliberate trade.
 */

/* See no-ambient-calls-in-domain.php for why: one generated case per (layer, forbidden prefix) pair. */
if (isset($argv[1]) && '--dump-rules' === $argv[1]) {
    echo json_encode(['layers' => FORBIDDEN_BY_LAYER], \JSON_THROW_ON_ERROR), "\n";

    exit(0);
}

exit(main());

function main(): int
{
    if (!is_dir(SRC)) {
        fwrite(\STDOUT, "layer-dependencies: api/src does not exist yet, nothing to check.\n");

        return 0;
    }

    $violations = [];
    $filesChecked = 0;

    foreach (FORBIDDEN_BY_LAYER as $layer => $forbidden) {
        $layerDir = SRC . '/' . $layer;

        if (!is_dir($layerDir)) {
            continue;
        }

        foreach (phpFilesIn($layerDir) as $file) {
            ++$filesChecked;
            $references = referencedNamespacesIn($file);

            foreach ($references as $reference => $line) {
                foreach ($forbidden as $forbiddenPrefix) {
                    if (str_starts_with($reference, $forbiddenPrefix . '\\')) {
                        $violations[] = sprintf(
                            '%s:%d — %s references %s, which is outward. Dependencies point inward only.',
                            relative($file),
                            $line,
                            $layer,
                            $reference,
                        );
                    }
                }

                if ('Domain' === $layer && isDisallowedVendorReference($reference)) {
                    $violations[] = sprintf(
                        '%s:%d — Domain references the third-party namespace %s. The domain layer has '
                        . 'no Composer dependencies; see DOMAIN_VENDOR_ALLOWLIST in this gate.',
                        relative($file),
                        $line,
                        $reference,
                    );
                }
            }
        }
    }

    if ([] !== $violations) {
        fwrite(\STDERR, "layer-dependencies: FAIL\n\n");

        foreach ($violations as $violation) {
            fwrite(\STDERR, '  ' . $violation . "\n");
        }

        fwrite(\STDERR, "\nSee CLAUDE.md, \"Architecture\".\n");

        return 1;
    }

    // A gate that inspected nothing must not report OK — its siblings hard-fail in this state, and this
    // one printed "OK — 0 file(s)" after a directory rename, which is a green gate that checked nothing.
    if (0 === $filesChecked) {
        fwrite(\STDERR, sprintf(
            "layer-dependencies: FAIL — inspected 0 files. Expected PHP under %s. Were the layer "
            . "directories renamed or moved?\n",
            implode(', ', array_map(static fn(string $l): string => 'api/src/' . $l, array_keys(FORBIDDEN_BY_LAYER))),
        ));

        return 1;
    }

    fwrite(\STDOUT, sprintf(
        "layer-dependencies: OK — %d file(s) in %s respect the inward-only rule.\n",
        $filesChecked,
        implode(', ', array_keys(FORBIDDEN_BY_LAYER)),
    ));

    return 0;
}

/**
 * Every namespace this file references, whether by `use` or written out inline.
 *
 * @return array<string, int> fully-qualified name => line number of the first reference
 */
function referencedNamespacesIn(string $file): array
{
    $source = file_get_contents($file);

    if (false === $source) {
        fwrite(\STDERR, "Could not read {$file}\n");
        exit(1);
    }

    $tokens = \PhpToken::tokenize($source);
    $references = [];
    $count = count($tokens);

    for ($index = 0; $index < $count; ++$index) {
        $token = $tokens[$index];

        // A qualified name token holds the whole dotted path: Twes\Domain\Money\Money.
        if (!$token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        // Skip the file's own `namespace X;` declaration — a layer may of course name itself.
        $previous = previousMeaningfulToken($tokens, $index);

        if (null !== $previous && $previous->is(\T_NAMESPACE)) {
            continue;
        }

        $name = ltrim($token->text, '\\');

        if (!isset($references[$name])) {
            $references[$name] = $token->line;
        }
    }

    return $references;
}

/** @param list<\PhpToken> $tokens */
function previousMeaningfulToken(array $tokens, int $index): ?\PhpToken
{
    for ($cursor = $index - 1; $cursor >= 0; --$cursor) {
        if (!$tokens[$cursor]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
            return $tokens[$cursor];
        }
    }

    return null;
}

function isDisallowedVendorReference(string $reference): bool
{
    if (str_starts_with($reference, 'Twes\\')) {
        return false;
    }

    // Global symbol — PHP's own classes and functions. See the note above.
    if (!str_contains($reference, '\\')) {
        return false;
    }

    foreach (DOMAIN_VENDOR_ALLOWLIST as $allowed) {
        if ($reference === $allowed || str_starts_with($reference, $allowed . '\\')) {
            return false;
        }
    }

    return true;
}

/** @return list<string> */
function phpFilesIn(string $directory): array
{
    $files = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

    /** @var \SplFileInfo $entry */
    foreach ($iterator as $entry) {
        if ($entry->isFile() && 'php' === $entry->getExtension()) {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

function relative(string $path): string
{
    $root = realpath(REPO_ROOT);

    return false === $root ? $path : str_replace($root . '/', '', $path);
}
