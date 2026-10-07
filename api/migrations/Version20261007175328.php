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
 * The activity journal reads one company's entries by moment; without an index starting at the company, every page
 * would walk the whole platform's log.
 */
final class Version20261007175328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index the audit log by company and moment for the activity journal.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_audit_log_company_at ON audit_log (company_id, at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_log_company_at');
    }
}
