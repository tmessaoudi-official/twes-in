<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The PDF an invoice or a credit note was issued with (docs/SPEC.md § 7, 2026-09-14), kept as delivery notes keep theirs. */
final class Version20260915110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoice PDF stored at issue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD pdf_file_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_invoice_pdf_file ON invoice (pdf_file_id)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT fk_invoice_pdf_file FOREIGN KEY (pdf_file_id) REFERENCES file (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP CONSTRAINT fk_invoice_pdf_file');
        $this->addSql('DROP INDEX idx_invoice_pdf_file');
        $this->addSql('ALTER TABLE invoice DROP pdf_file_id');
    }
}
