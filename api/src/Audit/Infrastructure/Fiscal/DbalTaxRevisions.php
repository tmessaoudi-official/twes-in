<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\Fiscal;

use App\Fiscal\Application\TaxComponent\ManageTaxComponents;
use App\Fiscal\Application\TaxComponent\TaxRevisions;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Reads the activity log for Fiscal's taxes. */
final readonly class DbalTaxRevisions implements TaxRevisions
{
    public function __construct(private Connection $connection)
    {
    }

    public function revisedByAPerson(Uuid $companyId): bool
    {
        return Rows::bool($this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM audit_log WHERE company_id = :company AND entity_type = :type AND actor_user_id IS NOT NULL)',
            ['company' => $companyId->toRfc4122(), 'type' => ManageTaxComponents::ENTITY_TYPE],
        ));
    }
}
