<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Application;

use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Company;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The subjects of « À surveiller » every module declared, for one company and one member. */
final readonly class WatchCatalogue
{
    /** @var list<DeclaresWatch> */
    private array $declarations;

    /** @param iterable<DeclaresWatch> $declarations */
    public function __construct(#[AutowireIterator('app.watch.declarations')] iterable $declarations, private ModuleStates $modules)
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
     * What this member should watch in the company now: each subject of every module switched on whose permission the
     * member's role grants, with its count, in a stable order. A subject with nothing in it is left out.
     *
     * @param \Closure(string): bool $may whether the member's role in the company grants a permission
     *
     * @return list<array{kind: string, count: int}>
     */
    public function counts(Company $company, \DateTimeImmutable $today, \Closure $may): array
    {
        $counts = [];
        foreach ($this->declarations as $declaration) {
            if (!$this->shown($declaration, $company, $may)) {
                continue;
            }
            foreach ($declaration->kinds() as $kind) {
                $count = $declaration->count($kind, $company, $today);
                if ($count > 0) {
                    $counts[] = ['kind' => $kind, 'count' => $count];
                }
            }
        }

        return $counts;
    }

    /**
     * The module that answers a kind, or null when there is none this member may see: a kind nobody declared, one of a
     * module switched off and one the role may not read are all the same absence, so asking by name learns nothing.
     *
     * @param \Closure(string): bool $may
     */
    public function declarationOf(string $kind, Company $company, \Closure $may): ?DeclaresWatch
    {
        foreach ($this->declarations as $declaration) {
            if (\in_array($kind, $declaration->kinds(), true)) {
                return $this->shown($declaration, $company, $may) ? $declaration : null;
            }
        }

        return null;
    }

    /** @param \Closure(string): bool $may */
    private function shown(DeclaresWatch $declaration, Company $company, \Closure $may): bool
    {
        $module = $declaration->module();

        return (null === $module || $this->modules->isEnabled($company->getId(), $module)) && $may($declaration->permission());
    }
}
