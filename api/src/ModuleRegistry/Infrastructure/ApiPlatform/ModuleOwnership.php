<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\ApiPlatform;

use App\ModuleRegistry\Application\DeclaresModule;

/**
 * Which module a class belongs to: the one declared in the same `App\Module\<Name>\` namespace. Ownership follows the
 * directory, so a resource added to a module is covered without being listed anywhere. A declaration outside
 * src/Module/ (the test environment's fixture) owns nothing.
 */
final readonly class ModuleOwnership
{
    private const string MODULE_NAMESPACE = '/^App\\\\Module\\\\[A-Za-z0-9]+\\\\/';

    /** @var array<string, string> module key by namespace prefix */
    private array $owners;

    /**
     * @param iterable<DeclaresModule> $declarations
     *
     * @throws \LogicException when one module directory declares two modules
     */
    public function __construct(iterable $declarations)
    {
        $owners = [];
        foreach ($declarations as $declaration) {
            if (1 !== preg_match(self::MODULE_NAMESPACE, $declaration::class, $match)) {
                continue;
            }
            if (isset($owners[$match[0]])) {
                throw new \LogicException(\sprintf('%s declares two modules, %s and %s.', $match[0], $owners[$match[0]], $declaration->manifest()->key));
            }
            $owners[$match[0]] = $declaration->manifest()->key;
        }
        $this->owners = $owners;
    }

    /** @return string|null the key of the module the class belongs to, null for the core */
    public function ownerOf(string $class): ?string
    {
        foreach ($this->owners as $prefix => $key) {
            if (str_starts_with($class, $prefix)) {
                return $key;
            }
        }

        return null;
    }
}
