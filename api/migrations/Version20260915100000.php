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
 * What issuing an invoice fixes (docs/SPEC.md § 7, 2026-09-14): the due day, the language, what the customer was
 * called, the footer and mentions it prints, who issued it and when, and every figure, so an issued document is
 * answered from these columns and never recomputed. What is paid and credited starts at zero.
 */
final class Version20260915100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoice issue: snapshots and frozen figures.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD due_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD language VARCHAR(8) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD customer_snapshot JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD footer_snapshot TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD mentions_snapshot JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD issued_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD issued_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD subtotal_net NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD document_discount NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD total_net NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD tax_breakdown JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD total_tax NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD fixed_taxes JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD total_gross NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD withholdings JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD withholding_amount NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD amount_paid NUMERIC(14, 3) DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE invoice ADD amount_credited NUMERIC(14, 3) DEFAULT \'0\' NOT NULL');
        $this->addSql('ALTER TABLE invoice ADD amount_due NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_line ADD line_net NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_line ADD line_tax NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_line ADD line_gross NUMERIC(14, 3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line DROP line_net, DROP line_tax, DROP line_gross');
        $this->addSql('ALTER TABLE invoice DROP due_date, DROP language, DROP customer_snapshot, DROP footer_snapshot, DROP mentions_snapshot, DROP issued_at, DROP issued_by, DROP subtotal_net, DROP document_discount, DROP total_net, DROP tax_breakdown, DROP total_tax, DROP fixed_taxes, DROP total_gross, DROP withholdings, DROP withholding_amount, DROP amount_paid, DROP amount_credited, DROP amount_due');
    }
}
