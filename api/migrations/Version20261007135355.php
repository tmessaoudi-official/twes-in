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
 * Factures d'acompte (docs/fiscal FR.md and TN.md § 2b): an invoice says whether it is a deposit and which quote it
 * was drafted from, and a line of the final invoice names the deposit it gives back, each of its taxes the amount the
 * deposit charged. Every invoice written before is no deposit and gives none back.
 */
final class Version20261007135355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deposit invoices, and the lines giving them back.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD deposit BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE invoice ADD quote_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_invoice_quote ON invoice (company_id, quote_id) WHERE (quote_id IS NOT NULL)');
        $this->addSql('ALTER TABLE invoice_line ADD deducts_invoice_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice_line ADD CONSTRAINT fk_invoice_line_deducts_invoice FOREIGN KEY (deducts_invoice_id) REFERENCES invoice (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_invoice_line_deducts_invoice ON invoice_line (deducts_invoice_id)');
        $this->addSql('ALTER TABLE invoice_line_tax ADD deducted_amount NUMERIC(14, 3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line_tax DROP deducted_amount');
        $this->addSql('DROP INDEX idx_invoice_line_deducts_invoice');
        $this->addSql('ALTER TABLE invoice_line DROP CONSTRAINT fk_invoice_line_deducts_invoice');
        $this->addSql('ALTER TABLE invoice_line DROP deducts_invoice_id');
        $this->addSql('DROP INDEX idx_invoice_quote');
        $this->addSql('ALTER TABLE invoice DROP quote_id');
        $this->addSql('ALTER TABLE invoice DROP deposit');
    }
}
