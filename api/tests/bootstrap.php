<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

$composerAutoloader = __DIR__ . '/../vendor/autoload.php';

if (is_file($composerAutoloader)) {
    require $composerAutoloader;

    return;
}

spl_autoload_register(static function (string $class): void {
    /** @var array<string, string> $prefixes PSR-4 prefix => absolute base directory */
    $prefixes = [
        'Twes\\Tests\\' => __DIR__,
        'Twes\\' => __DIR__ . '/../src',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . '/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;

            return;
        }
    }
});
