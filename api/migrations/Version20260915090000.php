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
 * G7 invoices module: an invoice or a credit note of an establishment to a customer, its lines with a discount rate,
 * each line's taxes with the rate they had when it was written, and the fixed charges and withholdings on the whole
 * document with the amount, rate and threshold they had.
 */
final class Version20260915090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoices and credit notes, their lines, their line taxes and their document taxes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice (id UUID NOT NULL, company_id UUID NOT NULL, document_type VARCHAR(16) NOT NULL, corrects_invoice_id UUID DEFAULT NULL, establishment_id UUID NOT NULL, customer_id UUID NOT NULL, status VARCHAR(16) NOT NULL, number VARCHAR(64) DEFAULT NULL, issue_date DATE DEFAULT NULL, supply_date DATE DEFAULT NULL, payment_terms_days INT DEFAULT NULL, customer_reference VARCHAR(64) DEFAULT NULL, notes_printed TEXT DEFAULT NULL, notes_internal TEXT DEFAULT NULL, discount_amount NUMERIC(14, 3) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invoice_company ON invoice (company_id)');
        $this->addSql('CREATE INDEX idx_invoice_establishment ON invoice (establishment_id)');
        $this->addSql('CREATE INDEX idx_invoice_customer ON invoice (customer_id)');
        $this->addSql('CREATE INDEX idx_invoice_corrects ON invoice (corrects_invoice_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_company_type_number ON invoice (company_id, document_type, number)');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT fk_invoice_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT fk_invoice_corrects FOREIGN KEY (corrects_invoice_id) REFERENCES invoice (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT fk_invoice_establishment FOREIGN KEY (establishment_id) REFERENCES establishment (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT fk_invoice_customer FOREIGN KEY (customer_id) REFERENCES customer (id) NOT DEFERRABLE');

        $this->addSql('CREATE TABLE invoice_line (id UUID NOT NULL, invoice_id UUID NOT NULL, position INT NOT NULL, product_id UUID DEFAULT NULL, description TEXT NOT NULL, quantity NUMERIC(14, 3) NOT NULL, unit_id UUID NOT NULL, unit_price_net NUMERIC(14, 4) NOT NULL, discount_rate NUMERIC(6, 3) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invoice_line_invoice ON invoice_line (invoice_id)');
        $this->addSql('CREATE INDEX idx_invoice_line_product ON invoice_line (product_id)');
        $this->addSql('CREATE INDEX idx_invoice_line_unit ON invoice_line (unit_id)');
        $this->addSql('ALTER TABLE invoice_line ADD CONSTRAINT fk_invoice_line_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_line ADD CONSTRAINT fk_invoice_line_product FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_line ADD CONSTRAINT fk_invoice_line_unit FOREIGN KEY (unit_id) REFERENCES unit (id) NOT DEFERRABLE');

        $this->addSql('CREATE TABLE invoice_line_tax (id UUID NOT NULL, line_id UUID NOT NULL, position INT NOT NULL, tax_component_id UUID NOT NULL, code VARCHAR(32) NOT NULL, rate NUMERIC(6, 3) NOT NULL, enters_vat_base BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invoice_line_tax_line ON invoice_line_tax (line_id)');
        $this->addSql('CREATE INDEX idx_invoice_line_tax_component ON invoice_line_tax (tax_component_id)');
        $this->addSql('ALTER TABLE invoice_line_tax ADD CONSTRAINT fk_invoice_line_tax_line FOREIGN KEY (line_id) REFERENCES invoice_line (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_line_tax ADD CONSTRAINT fk_invoice_line_tax_component FOREIGN KEY (tax_component_id) REFERENCES tax_component (id) NOT DEFERRABLE');

        $this->addSql('CREATE TABLE invoice_tax (id UUID NOT NULL, invoice_id UUID NOT NULL, position INT NOT NULL, tax_component_id UUID NOT NULL, code VARCHAR(32) NOT NULL, kind VARCHAR(32) NOT NULL, rate NUMERIC(6, 3) DEFAULT NULL, amount NUMERIC(14, 3) DEFAULT NULL, threshold NUMERIC(14, 3) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invoice_tax_invoice ON invoice_tax (invoice_id)');
        $this->addSql('CREATE INDEX idx_invoice_tax_component ON invoice_tax (tax_component_id)');
        $this->addSql('ALTER TABLE invoice_tax ADD CONSTRAINT fk_invoice_tax_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_tax ADD CONSTRAINT fk_invoice_tax_component FOREIGN KEY (tax_component_id) REFERENCES tax_component (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE invoice_tax');
        $this->addSql('DROP TABLE invoice_line_tax');
        $this->addSql('DROP TABLE invoice_line');
        $this->addSql('DROP TABLE invoice');
    }
}
