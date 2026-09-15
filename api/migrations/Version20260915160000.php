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
 * G9 expenses and attachments (docs/SPEC.md § 4 expense, expense_category, attachment): what a company spent, under
 * which category, and the files attached to what it keeps. A vendor names its default expense category by id only,
 * without a foreign key, so vendors stay independent of the expenses module (docs/SPEC.md § 7, 2026-09-15).
 */
final class Version20260915160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expenses, expense categories and attachments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE expense_category (id UUID NOT NULL, company_id UUID NOT NULL, parent_id UUID DEFAULT NULL, name VARCHAR(120) NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_expense_category_company ON expense_category (company_id)');
        $this->addSql('CREATE INDEX idx_expense_category_parent ON expense_category (parent_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_expense_category_company_name ON expense_category (company_id, name)');
        $this->addSql('ALTER TABLE expense_category ADD CONSTRAINT fk_expense_category_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense_category ADD CONSTRAINT fk_expense_category_parent FOREIGN KEY (parent_id) REFERENCES expense_category (id) NOT DEFERRABLE');

        $this->addSql('CREATE TABLE expense (id UUID NOT NULL, company_id UUID NOT NULL, vendor_id UUID DEFAULT NULL, category_id UUID DEFAULT NULL, tax_component_id UUID DEFAULT NULL, status VARCHAR(16) NOT NULL, expense_date DATE NOT NULL, reference VARCHAR(64) DEFAULT NULL, description VARCHAR(200) NOT NULL, amount_net NUMERIC(14, 3) NOT NULL, tax_rate NUMERIC(6, 3) DEFAULT NULL, tax_amount NUMERIC(14, 3) NOT NULL, amount_gross NUMERIC(14, 3) NOT NULL, currency VARCHAR(3) NOT NULL, payment_method VARCHAR(16) DEFAULT NULL, payment_date DATE DEFAULT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_expense_company_date ON expense (company_id, expense_date)');
        $this->addSql('CREATE INDEX idx_expense_vendor ON expense (vendor_id)');
        $this->addSql('CREATE INDEX idx_expense_category ON expense (category_id)');
        $this->addSql('CREATE INDEX idx_expense_tax_component ON expense (tax_component_id)');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT fk_expense_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT fk_expense_vendor FOREIGN KEY (vendor_id) REFERENCES vendor (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT fk_expense_category FOREIGN KEY (category_id) REFERENCES expense_category (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT fk_expense_tax_component FOREIGN KEY (tax_component_id) REFERENCES tax_component (id) NOT DEFERRABLE');

        $this->addSql('CREATE TABLE attachment (id UUID NOT NULL, company_id UUID NOT NULL, file_id UUID NOT NULL, entity_type VARCHAR(64) NOT NULL, entity_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_attachment_subject ON attachment (company_id, entity_type, entity_id)');
        $this->addSql('CREATE INDEX idx_attachment_file ON attachment (file_id)');
        $this->addSql('ALTER TABLE attachment ADD CONSTRAINT fk_attachment_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE attachment ADD CONSTRAINT fk_attachment_file FOREIGN KEY (file_id) REFERENCES file (id) NOT DEFERRABLE');

        $this->addSql('ALTER TABLE vendor ADD default_expense_category_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendor DROP default_expense_category_id');
        $this->addSql('DROP TABLE attachment');
        $this->addSql('DROP TABLE expense');
        $this->addSql('DROP TABLE expense_category');
    }
}
