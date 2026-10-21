<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\FirstSteps;

use App\FirstSteps\Application\DeclaresFirstStep;
use App\Fiscal\Application\TaxComponent\TaxRevisions;
use App\Fiscal\Infrastructure\ApiPlatform\Security\FiscalPermission;
use App\Tenancy\Domain\Model\Company;

/**
 * « Vérifier vos taxes »: the preset provisions the taxes when the company is created, so their existing proves
 * nothing. The step is done once a person has created or revised one, which only the activity log keeps.
 */
final readonly class TaxesStep implements DeclaresFirstStep
{
    public function __construct(private TaxRevisions $revisions)
    {
    }

    public function key(): string
    {
        return 'fiscal.taxes';
    }

    public function position(): int
    {
        return 20;
    }

    public function module(): ?string
    {
        return null;
    }

    public function permission(): string
    {
        return FiscalPermission::WRITE;
    }

    public function isDone(Company $company): bool
    {
        return $this->revisions->revisedByAPerson($company->getId());
    }
}
