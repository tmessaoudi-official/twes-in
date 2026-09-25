<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\FirstSteps;

use App\FirstSteps\Application\DeclaresFirstStep;
use App\Fiscal\Application\TaxComponent\ManageTaxComponents;
use App\Fiscal\Infrastructure\ApiPlatform\FiscalPermission;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Connection;

/**
 * « Vérifier vos taxes »: the preset provisions the taxes when the company is created, so their existing proves
 * nothing. The step is done once a person has created or revised one — the audit log keeps that, with its author;
 * provisioning records none.
 */
final readonly class TaxesStep implements DeclaresFirstStep
{
    public function __construct(private Connection $connection)
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
        return true === $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM audit_log WHERE company_id = :company AND entity_type = :type AND actor_user_id IS NOT NULL)',
            ['company' => $company->getId()->toRfc4122(), 'type' => ManageTaxComponents::ENTITY_TYPE],
        );
    }
}
