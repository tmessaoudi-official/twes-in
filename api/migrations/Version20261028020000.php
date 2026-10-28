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
 * When each company of before was first opened: an operator's first approval, read from the journal; else its own
 * creation, when it is open or closed now or an operator ever suspended it, since only an open company is closed down
 * or suspended; else never, which leaves a company refused or waiting as it is.
 */
final class Version20261028020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Every company of before learns when it was first opened.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE company c SET approved_at = COALESCE(
                (SELECT MIN(a.at) FROM audit_log a
                  WHERE a.entity_type = 'company' AND a.entity_id = c.id AND a.action = 'company.approved'),
                CASE WHEN c.status IN ('active', 'closed') OR EXISTS (
                    SELECT 1 FROM audit_log s
                     WHERE s.entity_type = 'company' AND s.entity_id = c.id AND s.action = 'company.suspended'
                ) THEN c.created_at END
            )
            WHERE c.approved_at IS NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        // The column goes with the migration before; nothing of this one is undone on its own.
    }
}
