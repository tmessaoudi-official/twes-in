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
 * G3b establishments and numbering series (docs/SPEC.md § 4). Exactly one default establishment per company, and one
 * default series per establishment and document type, are held by partial unique indexes. Existing companies get
 * their default establishment and series from the next seed, because a migration does not read the preset files.
 */
final class Version20260913213000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Establishments and numbering series.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE establishment (id UUID NOT NULL, company_id UUID NOT NULL, code VARCHAR(16) NOT NULL, name VARCHAR(120) NOT NULL, address_line1 VARCHAR(200) DEFAULT NULL, address_line2 VARCHAR(200) DEFAULT NULL, postal_code VARCHAR(20) DEFAULT NULL, city VARCHAR(120) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, email VARCHAR(254) DEFAULT NULL, is_default BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_establishment_company ON establishment (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_establishment_company_code ON establishment (company_id, code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_establishment_default ON establishment (company_id) WHERE (is_default)');
        $this->addSql('ALTER TABLE establishment ADD CONSTRAINT fk_establishment_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE numbering_series (id UUID NOT NULL, company_id UUID NOT NULL, establishment_id UUID NOT NULL, document_type VARCHAR(32) NOT NULL, format VARCHAR(64) NOT NULL, next_number INT NOT NULL, reset_period VARCHAR(16) NOT NULL, last_reset_year INT DEFAULT NULL, last_reset_month SMALLINT DEFAULT NULL, is_default BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_numbering_series_company ON numbering_series (company_id)');
        $this->addSql('CREATE INDEX idx_numbering_series_establishment ON numbering_series (establishment_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_numbering_series_default ON numbering_series (establishment_id, document_type) WHERE (is_default)');
        $this->addSql('ALTER TABLE numbering_series ADD CONSTRAINT fk_numbering_series_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE numbering_series ADD CONSTRAINT fk_numbering_series_establishment FOREIGN KEY (establishment_id) REFERENCES establishment (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE numbering_series');
        $this->addSql('DROP TABLE establishment');
    }
}
