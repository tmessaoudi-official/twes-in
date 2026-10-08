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
 * The recurring invoices: each names the invoice its drafts copy, how often, from which day and up to which, the next
 * occurrence by its count and its day, and how many drafts it made. The day of the next occurrence is indexed with the
 * company, which is how the worker finds what is due.
 */
final class Version20261008085154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The recurring invoices';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recurring_invoice (id UUID NOT NULL, model_invoice_id UUID NOT NULL, frequency VARCHAR(16) NOT NULL, starts_on DATE NOT NULL, ends_on DATE DEFAULT NULL, next_index INT NOT NULL, next_on DATE DEFAULT NULL, drafted INT NOT NULL, paused BOOLEAN NOT NULL, last_invoice_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_recurring_invoice_company_next ON recurring_invoice (company_id, next_on)');
        $this->addSql('CREATE INDEX IDX_489382CD979B1AD6 ON recurring_invoice (company_id)');
        $this->addSql('ALTER TABLE recurring_invoice ADD CONSTRAINT FK_489382CD979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE recurring_invoice ADD CONSTRAINT ck_recurring_invoice_frequency CHECK (frequency IN ('weekly', 'monthly', 'quarterly', 'yearly'))");
        $this->addSql('ALTER TABLE recurring_invoice ADD CONSTRAINT ck_recurring_invoice_counts CHECK (next_index >= 0 AND drafted >= 0 AND (ends_on IS NULL OR ends_on >= starts_on))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_invoice DROP CONSTRAINT FK_489382CD979B1AD6');
        $this->addSql('DROP TABLE recurring_invoice');
    }
}
