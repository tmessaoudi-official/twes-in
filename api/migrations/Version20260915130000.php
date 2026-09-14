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
 * Invoices drafted from delivery notes (docs/SPEC.md § 4 invoice_line and delivery_note, § 7 2026-09-14): the delivery note
 * line an invoice line invoices, and the issued invoice a delivery note is on. Ids, not foreign keys: each side is its
 * own module.
 */
final class Version20260915130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoice lines from delivery note lines, and invoiced delivery notes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line ADD source_delivery_note_line_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_invoice_line_source_delivery_note_line ON invoice_line (source_delivery_note_line_id)');
        $this->addSql('ALTER TABLE delivery_note ADD invoiced_by_invoice_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_delivery_note_invoiced_by_invoice ON delivery_note (invoiced_by_invoice_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_delivery_note_invoiced_by_invoice');
        $this->addSql('ALTER TABLE delivery_note DROP invoiced_by_invoice_id');
        $this->addSql('DROP INDEX idx_invoice_line_source_delivery_note_line');
        $this->addSql('ALTER TABLE invoice_line DROP source_delivery_note_line_id');
    }
}
