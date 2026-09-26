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
    /** The versions a planned module may be expected in: the first working version, or later. */
    public const array PLANNED = ['v1', 'later'];

    /**
     * @param list<string> $dependencies keys of the modules that must be on for this one to be on
     * @param list<string> $permissions  the permission strings the module's resources check
     * @param string|null  $planned      the version it is expected in, one of PLANNED, when it is not built yet
     *                                   (docs/SPEC.md § 7, 2026-09-26 10:08): listed, never on, and checking nothing
     *
     * @throws \LogicException when the key is not lowercase letters, digits and underscores, or a planned module is
     *                         expected in no version it names or already checks permissions
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public array $dependencies = [],
        public array $permissions = [],
        public ?string $planned = null,
    ) {
        if (1 !== preg_match(self::KEY, $key)) {
            throw new \LogicException(\sprintf('A module key is lowercase letters, digits and underscores, starting with a letter: %s.', $key));
        }
        if (null !== $planned && !\in_array($planned, self::PLANNED, true)) {
            throw new \LogicException(\sprintf('A planned module is expected in %s, not %s: %s.', implode(' or ', self::PLANNED), $planned, $key));
        }
        if (null !== $planned && [] !== $permissions) {
            throw new \LogicException(\sprintf('A planned module has no resources yet, so no permissions: %s.', $key));
        }
    }
}
