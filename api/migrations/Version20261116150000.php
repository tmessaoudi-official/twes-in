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
 * The home's money cards took `invoice.read` until `invoice.summary.read` existed, which lets a role read invoices at the
 * counter without the business's figures: a role a company made for itself and allowed to read invoices is granted the
 * new permission, so nobody loses a card with the update. The built-in roles are the seed's to define, so only a company's
 * own are touched. `jsonb_exists` rather than the `?` operator, which DBAL would read as a parameter.
 *
 * Nothing records which roles held `invoice.summary.read` before, so going down leaves the permissions as they are.
 */
final class Version20261116150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A company role that read invoices keeps the home\'s money cards under invoice.summary.read.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE role SET permissions = permissions || '["invoice.summary.read"]'::jsonb
            WHERE company_id IS NOT NULL
              AND jsonb_exists(permissions, 'invoice.read')
              AND NOT jsonb_exists(permissions, 'invoice.summary.read')
            SQL);
    }

    public function down(Schema $schema): void
    {
    }
}
