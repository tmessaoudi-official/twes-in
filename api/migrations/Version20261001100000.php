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
 * The open invoices, by company and due day, with the two columns the « À surveiller » count reads (a covering index: Doctrine's mapping has no INCLUDE, so they are key columns) (docs/SPEC.md § 7,
 * lists at scale). At a million invoices the late customers were counted by reading 212,000 pages of the table; this
 * index holds only the issued and partly paid invoices, and the count is an index-only scan of 28 MB.
 */
final class Version20261001100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The open invoices are indexed by due day for the late-customers count';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX idx_invoice_open_due ON invoice (company_id, due_date, customer_id, amount_due) WHERE document_type = 'invoice' AND status IN ('issued', 'partially_paid')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_invoice_open_due');
    }
}
