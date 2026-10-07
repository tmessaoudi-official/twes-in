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
 * Quotes, their lines and their line taxes (docs/SPEC.md § 7, 2026-10-07 10:21), and the trigram index a quotes list is
 * searched through. Its expression is the one DoctrineQuoteRepository::MATCHES_WORDS asks for, character for character:
 * PostgreSQL uses the index only when the query repeats it exactly.
 */
final class Version20261007084646 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quotes, their lines and their line taxes, and the trigram index they are searched with.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE quote (id UUID NOT NULL, status VARCHAR(16) NOT NULL, number VARCHAR(64) DEFAULT NULL, issue_date DATE DEFAULT NULL, valid_until DATE DEFAULT NULL, customer_reference VARCHAR(64) DEFAULT NULL, notes_printed TEXT DEFAULT NULL, notes_internal TEXT DEFAULT NULL, discount_amount NUMERIC(14, 3) DEFAULT NULL, customer_snapshot JSONB DEFAULT NULL, seller_snapshot JSONB DEFAULT NULL, print_settings JSONB DEFAULT NULL, answered_on DATE DEFAULT NULL, refusal_reason VARCHAR(500) DEFAULT NULL, invoice_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, establishment_id UUID NOT NULL, customer_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_quote_company ON quote (company_id)');
        $this->addSql('CREATE INDEX idx_quote_establishment ON quote (establishment_id)');
        $this->addSql('CREATE INDEX idx_quote_customer ON quote (customer_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_quote_company_number ON quote (company_id, number)');
        $this->addSql('CREATE TABLE quote_line (id UUID NOT NULL, position INT NOT NULL, description TEXT NOT NULL, quantity NUMERIC(14, 3) NOT NULL, unit_price_net NUMERIC(14, 4) NOT NULL, discount_rate NUMERIC(6, 3) DEFAULT NULL, quote_id UUID NOT NULL, company_id UUID NOT NULL, product_id UUID DEFAULT NULL, unit_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_quote_line_quote ON quote_line (quote_id)');
        $this->addSql('CREATE INDEX idx_quote_line_company ON quote_line (company_id)');
        $this->addSql('CREATE INDEX idx_quote_line_product ON quote_line (product_id)');
        $this->addSql('CREATE INDEX idx_quote_line_unit ON quote_line (unit_id)');
        $this->addSql('CREATE TABLE quote_line_tax (id UUID NOT NULL, position INT NOT NULL, code VARCHAR(32) NOT NULL, rate NUMERIC(6, 3) NOT NULL, enters_vat_base BOOLEAN NOT NULL, line_id UUID NOT NULL, company_id UUID NOT NULL, tax_component_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_quote_line_tax_line ON quote_line_tax (line_id)');
        $this->addSql('CREATE INDEX idx_quote_line_tax_company ON quote_line_tax (company_id)');
        $this->addSql('CREATE INDEX idx_quote_line_tax_component ON quote_line_tax (tax_component_id)');
        $this->addSql('ALTER TABLE quote ADD CONSTRAINT FK_6B71CBF4979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE quote ADD CONSTRAINT FK_6B71CBF48565851 FOREIGN KEY (establishment_id) REFERENCES establishment (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE quote ADD CONSTRAINT FK_6B71CBF49395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE quote_line ADD CONSTRAINT FK_43F3EB7CDB805178 FOREIGN KEY (quote_id) REFERENCES quote (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE quote_line ADD CONSTRAINT FK_43F3EB7C979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE quote_line ADD CONSTRAINT FK_43F3EB7C4584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE quote_line ADD CONSTRAINT FK_43F3EB7CF8BD700D FOREIGN KEY (unit_id) REFERENCES unit (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE quote_line_tax ADD CONSTRAINT FK_97F021BD4D7B7542 FOREIGN KEY (line_id) REFERENCES quote_line (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE quote_line_tax ADD CONSTRAINT FK_97F021BD979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE quote_line_tax ADD CONSTRAINT FK_97F021BD729B0C67 FOREIGN KEY (tax_component_id) REFERENCES tax_component (id) NOT DEFERRABLE');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_quote_search ON quote USING gin (search_text(
                number, customer_reference, jsonb_path_query_array(customer_snapshot, '$.*')::text
            ) gin_trgm_ops)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_quote_search');
        $this->addSql('ALTER TABLE quote DROP CONSTRAINT FK_6B71CBF4979B1AD6');
        $this->addSql('ALTER TABLE quote DROP CONSTRAINT FK_6B71CBF48565851');
        $this->addSql('ALTER TABLE quote DROP CONSTRAINT FK_6B71CBF49395C3F3');
        $this->addSql('ALTER TABLE quote_line DROP CONSTRAINT FK_43F3EB7CDB805178');
        $this->addSql('ALTER TABLE quote_line DROP CONSTRAINT FK_43F3EB7C979B1AD6');
        $this->addSql('ALTER TABLE quote_line DROP CONSTRAINT FK_43F3EB7C4584665A');
        $this->addSql('ALTER TABLE quote_line DROP CONSTRAINT FK_43F3EB7CF8BD700D');
        $this->addSql('ALTER TABLE quote_line_tax DROP CONSTRAINT FK_97F021BD4D7B7542');
        $this->addSql('ALTER TABLE quote_line_tax DROP CONSTRAINT FK_97F021BD979B1AD6');
        $this->addSql('ALTER TABLE quote_line_tax DROP CONSTRAINT FK_97F021BD729B0C67');
        $this->addSql('DROP TABLE quote');
        $this->addSql('DROP TABLE quote_line');
        $this->addSql('DROP TABLE quote_line_tax');
    }
}
