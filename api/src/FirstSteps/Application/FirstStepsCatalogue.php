<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\FirstSteps\Application;

use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Company;

/** Every context's first step, collected once (docs/SPEC.md § 3, the same shape as settings, imports and watches). */
final readonly class FirstStepsCatalogue
{
    /** @var list<DeclaresFirstStep> */
    private array $declarations;

    /** @param iterable<DeclaresFirstStep> $declarations */
    public function __construct(iterable $declarations, private ModuleStates $modules)
    {
        $byKey = [];
        foreach ($declarations as $declaration) {
            $key = $declaration->key();
            if (isset($byKey[$key])) {
                throw new \LogicException(\sprintf('The first step %s is declared twice.', $key));
            }
            $byKey[$key] = $declaration;
        }
        uasort($byKey, static fn (DeclaresFirstStep $a, DeclaresFirstStep $b): int => [$a->position(), $a->key()] <=> [$b->position(), $b->key()]);
        $this->declarations = array_values($byKey);
    }

    /**
     * The steps this member may do in the company, in the approved order, each with whether it is done: those of a
     * switched-off module and those whose permission the member's role lacks are left out.
     *
     * @param \Closure(string): bool $may whether the member's role in the company grants a permission
     *
     * @return list<FirstStep>
     */
    public function stepsFor(Company $company, \Closure $may): array
    {
        $steps = [];
        foreach ($this->declarations as $declaration) {
            $module = $declaration->module();
            if ((null === $module || $this->modules->isEnabled($company->getId(), $module)) && $may($declaration->permission())) {
                $steps[] = new FirstStep($declaration->key(), $declaration->isDone($company));
            }
        }

        return $steps;
    }
}
