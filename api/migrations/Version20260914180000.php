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
 * G6 delivery notes module: a note of an establishment to a customer, with its delivery address, its lines at
 * NUMERIC(14,3) quantities and NUMERIC(14,4) prices, and each line's taxes with the rate they had when it was written.
 */
final class Version20260914180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delivery notes, their lines and their line taxes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE delivery_note (id UUID NOT NULL, company_id UUID NOT NULL, establishment_id UUID NOT NULL, customer_id UUID NOT NULL, status VARCHAR(16) NOT NULL, number VARCHAR(64) DEFAULT NULL, issue_date DATE DEFAULT NULL, delivery_date DATE DEFAULT NULL, delivery_line1 VARCHAR(200) DEFAULT NULL, delivery_line2 VARCHAR(200) DEFAULT NULL, delivery_postal_code VARCHAR(20) DEFAULT NULL, delivery_city VARCHAR(120) DEFAULT NULL, delivery_country_code VARCHAR(2) DEFAULT NULL, customer_reference VARCHAR(64) DEFAULT NULL, remarks_printed TEXT DEFAULT NULL, notes_internal TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_delivery_note_company ON delivery_note (company_id)');
        $this->addSql('CREATE INDEX idx_delivery_note_establishment ON delivery_note (establishment_id)');
        $this->addSql('CREATE INDEX idx_delivery_note_customer ON delivery_note (customer_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_delivery_note_company_number ON delivery_note (company_id, number)');
        $this->addSql('ALTER TABLE delivery_note ADD CONSTRAINT fk_delivery_note_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE delivery_note ADD CONSTRAINT fk_delivery_note_establishment FOREIGN KEY (establishment_id) REFERENCES establishment (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE delivery_note ADD CONSTRAINT fk_delivery_note_customer FOREIGN KEY (customer_id) REFERENCES customer (id) NOT DEFERRABLE');

        $this->addSql('CREATE TABLE delivery_note_line (id UUID NOT NULL, delivery_note_id UUID NOT NULL, position INT NOT NULL, product_id UUID DEFAULT NULL, description TEXT NOT NULL, quantity NUMERIC(14, 3) NOT NULL, unit_id UUID NOT NULL, unit_price_net NUMERIC(14, 4) NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_delivery_note_line_note ON delivery_note_line (delivery_note_id)');
        $this->addSql('CREATE INDEX idx_delivery_note_line_product ON delivery_note_line (product_id)');
        $this->addSql('CREATE INDEX idx_delivery_note_line_unit ON delivery_note_line (unit_id)');
        $this->addSql('ALTER TABLE delivery_note_line ADD CONSTRAINT fk_delivery_note_line_note FOREIGN KEY (delivery_note_id) REFERENCES delivery_note (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE delivery_note_line ADD CONSTRAINT fk_delivery_note_line_product FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE delivery_note_line ADD CONSTRAINT fk_delivery_note_line_unit FOREIGN KEY (unit_id) REFERENCES unit (id) NOT DEFERRABLE');

        $this->addSql('CREATE TABLE delivery_note_line_tax (id UUID NOT NULL, line_id UUID NOT NULL, position INT NOT NULL, tax_component_id UUID NOT NULL, code VARCHAR(32) NOT NULL, rate NUMERIC(6, 3) NOT NULL, enters_vat_base BOOLEAN NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_delivery_note_line_tax_line ON delivery_note_line_tax (line_id)');
        $this->addSql('CREATE INDEX idx_delivery_note_line_tax_component ON delivery_note_line_tax (tax_component_id)');
        $this->addSql('ALTER TABLE delivery_note_line_tax ADD CONSTRAINT fk_delivery_note_line_tax_line FOREIGN KEY (line_id) REFERENCES delivery_note_line (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE delivery_note_line_tax ADD CONSTRAINT fk_delivery_note_line_tax_component FOREIGN KEY (tax_component_id) REFERENCES tax_component (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE delivery_note_line_tax');
        $this->addSql('DROP TABLE delivery_note_line');
        $this->addSql('DROP TABLE delivery_note');
    }
}
