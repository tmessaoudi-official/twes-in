<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

$header = <<<'HEADER'
SPDX-License-Identifier: AGPL-3.0-or-later
SPDX-FileCopyrightText: Takieddine MESSAOUDI
HEADER;

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->notPath(['config/bundles.php', 'config/reference.php'])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'header_comment' => [
            'header' => $header,
            'comment_type' => 'comment',
            'location' => 'after_open',
            'separate' => 'both',
        ],
    ])
    ->setFinder($finder)
    // The API runs PHP 8.6 and php-cs-fixer reads syntax up to 8.5 (ConfigInterface::PHP_VERSION_SYNTAX_SUPPORTED): it
    // refuses to start on a newer PHP unless allowed. The code holds no 8.6-only syntax, which the fixer could misread;
    // drop this once a release supports 8.6 (docs/SPEC.md § 7).
    ->setUnsupportedPhpVersionAllowed(true)
;
