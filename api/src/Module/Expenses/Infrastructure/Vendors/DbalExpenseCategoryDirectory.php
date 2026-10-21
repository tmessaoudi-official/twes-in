<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Vendors;

use App\Module\Vendors\Application\ExpenseCategoryDirectory;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** The expenses module's answer to the vendors' question about its categories, read from its own table. */
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

    public function categoriesInCompany(Uuid $companyId): array
    {
        $categories = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, name, is_active FROM expense_category WHERE company_id = ? ORDER BY name', [$companyId->toRfc4122()]) as $row) {
            $categories[Rows::text($row['id'])] = ['name' => Rows::text($row['name']), 'active' => Rows::bool($row['is_active'])];
        }

        return $categories;
    }
}
