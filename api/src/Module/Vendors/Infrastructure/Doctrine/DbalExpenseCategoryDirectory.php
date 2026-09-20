<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\Doctrine;

use App\Module\Vendors\Application\ExpenseCategoryDirectory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Reads the expense_category table directly, so vendors import nothing from the expenses module. */
final readonly class DbalExpenseCategoryDirectory implements ExpenseCategoryDirectory
{
    public function __construct(private Connection $connection)
    {
    }

    public function isActiveInCompany(Uuid $categoryId, Uuid $companyId): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM expense_category WHERE id = ? AND company_id = ? AND is_active',
            [$categoryId->toRfc4122(), $companyId->toRfc4122()],
        );
    }

    public function idOfActiveNameInCompany(string $name, Uuid $companyId): ?Uuid
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM expense_category WHERE name = ? AND company_id = ? AND is_active',
            [$name, $companyId->toRfc4122()],
        );

        return \is_string($id) ? Uuid::fromString($id) : null;
    }
}
