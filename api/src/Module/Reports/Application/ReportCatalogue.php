<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The reports every module declared, for one company and one member, in the order they are offered. */
final readonly class ReportCatalogue
{
    /** The key of the Reports module, whose switch takes the whole area away. */
    public const string MODULE = 'reports';

    /**
     * The order of the Reports area (docs/SPEC.md § 7, 2026-09-24 12:05); a report not named here comes after these, by
     * key, so a new one is offered before it is placed.
     */
    public const array ORDER = [
        'takings', 'aged-balance', 'sales-by-product', 'sales-by-customer', 'vat-by-rate', 'withholding-suffered',
        'expenses', 'credit-notes', 'stock-movements', 'activity',
    ];

    /** @var list<DeclaresReport> */
    private array $declarations;

    /** @param iterable<DeclaresReport> $declarations */
    public function __construct(#[AutowireIterator('app.report.declarations')] iterable $declarations, private ModuleStates $modules)
    {
        $byKey = [];
        foreach ($declarations as $declaration) {
            $key = $declaration->key();
            if (1 !== preg_match('/^[a-z][a-z-]{1,39}$/', $key)) {
                throw new \LogicException(\sprintf('A report is named in lowercase words joined by dashes: %s.', $key));
            }
            if (isset($byKey[$key])) {
                throw new \LogicException(\sprintf('The report %s is declared twice.', $key));
            }
            $byKey[$key] = $declaration;
        }
        $place = array_flip(self::ORDER);
        uksort($byKey, static fn (string $a, string $b): int => [$place[$a] ?? \PHP_INT_MAX, $a] <=> [$place[$b] ?? \PHP_INT_MAX, $b]);
        $this->declarations = array_values($byKey);
    }

    /**
     * The reports this member may read in the company, with the Reports module switched on: each of a module switched on
     * (or of the core) whose permission the member's role grants.
     *
     * @return list<DeclaresReport>
     */
    public function offered(ReportContext $context): array
    {
        if (!$this->modules->isEnabled($context->company->getId(), self::MODULE)) {
            return [];
        }

        return array_values(array_filter($this->declarations, fn (DeclaresReport $declaration): bool => $this->shown($declaration, $context->company, $context->may)));
    }

    /**
     * The report by that key, or null when there is none this member may read: a key nobody declared, a report of a module
     * switched off and one the role may not read are the same absence, so asking by name learns nothing.
     */
    public function declarationOf(string $key, ReportContext $context): ?DeclaresReport
    {
        foreach ($this->offered($context) as $declaration) {
            if ($declaration->key() === $key) {
                return $declaration;
            }
        }

        return null;
    }

    /** @param \Closure(string): bool $may */
    private function shown(DeclaresReport $declaration, Company $company, \Closure $may): bool
    {
        $module = $declaration->module();

        return (null === $module || $this->modules->isEnabled($company->getId(), $module)) && $may($declaration->permission());
    }
}
