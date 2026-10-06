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
 * A credit note took `invoice.write` until `invoice.credit` existed (docs/SPEC.md § 7, audit 2026-10-06 B-12): a role a
 * company made for itself and allowed to write invoices is granted the new permission, so it keeps doing what it did.
 * The built-in roles are the seed's to define, so only a company's own are touched. `jsonb_exists` rather than the `?`
 * operator, which DBAL would read as a parameter.
 *
 * Nothing records which roles held `invoice.credit` before, so going down leaves the permissions as they are.
 */
final class Version20261006100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A company role that wrote invoices keeps its credit notes under invoice.credit.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE role SET permissions = permissions || '["invoice.credit"]'::jsonb
            WHERE company_id IS NOT NULL
              AND jsonb_exists(permissions, 'invoice.write')
              AND NOT jsonb_exists(permissions, 'invoice.credit')
            SQL);
    }

    public function down(Schema $schema): void
    {
    }
}
