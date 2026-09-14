<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

/**
 * What a module says about itself (docs/SPEC.md § 3 Modules): its key, the translation key of its name, the modules
 * it cannot work without, and the permissions it introduces. Its settings are declared through DeclaresSettings,
 * each naming the module; its navigation entries live in its web feature, where its routes are compiled.
 */
final readonly class ModuleManifest
{
    public const string KEY = '/^[a-z][a-z0-9_]{1,39}$/';

    /**
     * @param list<string> $dependencies keys of the modules that must be on for this one to be on
     * @param list<string> $permissions  the permission strings the module's resources check
     *
     * @throws \LogicException when the key is not lowercase letters, digits and underscores
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public array $dependencies = [],
        public array $permissions = [],
    ) {
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \LogicException(\sprintf('A module key is lowercase letters, digits and underscores, starting with a letter: %s.', $key));
        }
    }
}
