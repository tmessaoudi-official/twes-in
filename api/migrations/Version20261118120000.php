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
 * The journal showed a sign-in's address to whoever held `user.write` until `audit.address.read` existed, which lets a
 * company give the team's management and people's whereabouts apart: a role a company made for itself that managed the
 * team is granted the new permission, so nobody loses a column with the update. The built-in roles are the seed's to
 * define, so only a company's own are touched. `jsonb_exists` rather than the `?` operator, which DBAL would read as a
 * parameter.
 *
 * Nothing records which roles held `audit.address.read` before, so going down leaves the permissions as they are.
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A company role that managed the team keeps reading sign-in addresses under audit.address.read.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE role SET permissions = permissions || '["audit.address.read"]'::jsonb
            WHERE company_id IS NOT NULL
              AND jsonb_exists(permissions, 'user.write')
              AND NOT jsonb_exists(permissions, 'audit.address.read')
            SQL);
    }

    public function down(Schema $schema): void
    {
    }
}
