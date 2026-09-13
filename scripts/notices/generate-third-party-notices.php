#!/usr/bin/env php
<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

require __DIR__.'/../lib/dependency-inventory.php';

// Writes THIRD-PARTY-NOTICES.md from the two lock files and the vendored fonts. Run it in the same change that adds
// a dependency or a font.
$args = parseArguments($argv);
$path = $args['root'].'/THIRD-PARTY-NOTICES.md';
file_put_contents($path, renderNotices(dependencyInventory($args['root']), vendoredFonts($args['root'])));
echo "wrote $path\n";
