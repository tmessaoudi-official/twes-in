<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Application;

use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Company;

/** Every module's watch declaration, collected once (docs/SPEC.md § 3, the same shape as settings and imports). */
final readonly class WatchCatalogue
{
    /** @var list<DeclaresWatch> */
    private array $declarations;

    /** @param iterable<DeclaresWatch> $declarations */
    public function __construct(iterable $declarations, private ModuleStates $modules)
    {
        $byKey = [];
        foreach ($declarations as $declaration) {
            $key = $declaration->key();
            if (isset($byKey[$key])) {
                throw new \LogicException(\sprintf('The watch %s is declared twice.', $key));
            }
            $byKey[$key] = $declaration;
        }
        ksort($byKey);

        $this->declarations = array_values($byKey);
    }

    /**
     * What this member should watch in the company now: the conditions of every module switched on whose permission
     * the member's role grants, in a stable order.
     *
     * @param \Closure(string): bool $may whether the member's role in the company grants a permission
     *
     * @return list<WatchItem>
     */
    public function itemsFor(Company $company, \DateTimeImmutable $today, \Closure $may): array
    {
        $items = [];
        foreach ($this->declarations as $declaration) {
            if ($this->modules->isEnabled($company->getId(), $declaration->module()) && $may($declaration->permission())) {
                array_push($items, ...$declaration->itemsFor($company, $today));
            }
        }

        return $items;
    }
}
