<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Deleting and merging customers took `customer.write` until `customer.remove` existed, which lets a clerk add customers
 * without removing them: a role a company made for itself and allowed to write customers is granted the new permission,
 * so it keeps doing what it did. The built-in roles are the seed's to define, so only a company's own are touched.
 * `jsonb_exists` rather than the `?` operator, which DBAL would read as a parameter.
 *
 * Nothing records which roles held `customer.remove` before, so going down leaves the permissions as they are.
 */
final class Version20261021131500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A company role that wrote customers keeps deleting and merging them under customer.remove.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE role SET permissions = permissions || '["customer.remove"]'::jsonb
            WHERE company_id IS NOT NULL
              AND jsonb_exists(permissions, 'customer.write')
              AND NOT jsonb_exists(permissions, 'customer.remove')
            SQL);
    }

    public function down(Schema $schema): void
    {
    }
}
