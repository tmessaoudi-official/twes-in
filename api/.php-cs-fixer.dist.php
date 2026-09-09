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
;
