<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

$header = <<<'HEADER'
    This file is part of twes-in.

    (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>

    SPDX-License-Identifier: AGPL-3.0-or-later
    HEADER;

return new PhpCsFixer\Config()
    ->setRiskyAllowed(true)
    // PHP 8.5 is newer than this php-cs-fixer release knows about; the pinned runtime is deliberate.
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRules([
        '@PER-CS2.0' => true,
        '@PHP84Migration' => true,

        // Every file, no exceptions: without it a "1" from a request silently satisfies an int
        // parameter, and in a billing domain a coerced type is a wrong number.
        'declare_strict_types' => true,

        // Keeps the SPDX identifier on every file mechanically, so scripts/gates/spdx-headers.sh has
        // nothing left to catch. Belt and braces on purpose: the gate is the guarantee, this is the
        // convenience that means nobody has to remember.
        'header_comment' => [
            'header' => $header,
            'comment_type' => 'comment',
            'location' => 'after_open',
            'separate' => 'both',
        ],

        // `null === $x`, not `$x === null`. An accidental single `=` is then a parse error rather than
        // a silent assignment inside a condition.
        'yoda_style' => true,

        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters', 'match']],
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_separation' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'strict_comparison' => true,
        'strict_param' => true,
        'void_return' => true,
        'single_line_throw' => false,
        'concat_space' => ['spacing' => 'one'],
    ])
    ->setFinder(
        new PhpCsFixer\Finder()
            ->in([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/../scripts/gates'])
            // MoneyWeakModeTest deliberately omits declare(strict_types=1) — that absence IS the test,
            // since it reproduces the weak-mode caller where a float was silently coerced to an int.
            // The declare_strict_types rule would destroy it, and the test would still pass while
            // proving nothing.
            //
            // A filter callback rather than notPath()/notName(): both of those were silently ignored
            // here. And the file asserts the property itself, in
            // testThisFileIsNotInStrictTypesModeBecauseThatIsTheWholePoint — which is what actually
            // caught it, twice. Treat this line as the convenience and that test as the guarantee.
            ->filter(static fn(\SplFileInfo $file): bool => 'MoneyWeakModeTest.php' !== $file->getFilename())
            ->append([__FILE__]),
    )
    ->setCacheFile(__DIR__ . '/var/php-cs-fixer.cache');
