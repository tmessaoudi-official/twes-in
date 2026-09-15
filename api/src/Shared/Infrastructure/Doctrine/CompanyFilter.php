<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\CompanyOwned;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * Scopes every query on a company-owned entity to the company a request acts for (docs/SPEC.md § 3 Tenancy, review
 * S5). Off by default: CompanyGuard switches it on once it has resolved the company, CompanyFilterLifter switches it
 * off when the request ends, so signed-out requests, platform endpoints and the console are never filtered.
 */
final class CompanyFilter extends SQLFilter
{
    public const string NAME = 'company';
    public const string COMPANY = 'company';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (!is_a($targetEntity->getName(), CompanyOwned::class, true)) {
            return '';
        }

        return \sprintf('%s.company_id = %s', $targetTableAlias, $this->getParameter(self::COMPANY));
    }
}
