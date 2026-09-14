<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Stored files, and the PDF a delivery note was issued with (docs/SPEC.md § 4 file, § 7 2026-09-14). */
final class Version20260914210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stored files and the delivery note PDF';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE file (id UUID NOT NULL, company_id UUID NOT NULL, storage_key VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, mime VARCHAR(127) NOT NULL, size INT NOT NULL, sha256 CHAR(64) NOT NULL, uploaded_by UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_file_company ON file (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_file_storage_key ON file (storage_key)');
        $this->addSql('ALTER TABLE file ADD CONSTRAINT fk_file_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE delivery_note ADD pdf_file_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_delivery_note_pdf_file ON delivery_note (pdf_file_id)');
        $this->addSql('ALTER TABLE delivery_note ADD CONSTRAINT fk_delivery_note_pdf_file FOREIGN KEY (pdf_file_id) REFERENCES file (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note DROP CONSTRAINT fk_delivery_note_pdf_file');
        $this->addSql('DROP INDEX idx_delivery_note_pdf_file');
        $this->addSql('ALTER TABLE delivery_note DROP pdf_file_id');
        $this->addSql('DROP TABLE file');
    }
}
