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
 * G4 customers, their contacts and customer groups (docs/SPEC.md § 4). A customer's number is unique in its company,
 * at most one contact per customer is its primary one (a partial unique index), and a group still holding customers
 * cannot be dropped (no cascade from customer_group to customer).
 */
final class Version20260914090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customers, contacts and customer groups.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_group (id UUID NOT NULL, company_id UUID NOT NULL, name VARCHAR(120) NOT NULL, description VARCHAR(500) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_customer_group_company ON customer_group (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_customer_group_company_name ON customer_group (company_id, name)');
        $this->addSql('ALTER TABLE customer_group ADD CONSTRAINT fk_customer_group_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE customer (id UUID NOT NULL, company_id UUID NOT NULL, number VARCHAR(32) NOT NULL, kind VARCHAR(16) NOT NULL, customer_group_id UUID DEFAULT NULL, tax_regime_id UUID NOT NULL, name VARCHAR(200) NOT NULL, legal_name VARCHAR(200) DEFAULT NULL, identifiers JSONB DEFAULT \'{}\' NOT NULL, email VARCHAR(254) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, website VARCHAR(200) DEFAULT NULL, billing_line1 VARCHAR(200) DEFAULT NULL, billing_line2 VARCHAR(200) DEFAULT NULL, billing_postal_code VARCHAR(20) DEFAULT NULL, billing_city VARCHAR(120) DEFAULT NULL, billing_country_code VARCHAR(2) DEFAULT NULL, shipping_line1 VARCHAR(200) DEFAULT NULL, shipping_line2 VARCHAR(200) DEFAULT NULL, shipping_postal_code VARCHAR(20) DEFAULT NULL, shipping_city VARCHAR(120) DEFAULT NULL, shipping_country_code VARCHAR(2) DEFAULT NULL, default_tax_component_ids JSONB DEFAULT \'[]\' NOT NULL, default_discount_rate NUMERIC(6, 3) DEFAULT NULL, notes TEXT DEFAULT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_customer_company ON customer (company_id)');
        $this->addSql('CREATE INDEX idx_customer_group ON customer (customer_group_id)');
        $this->addSql('CREATE INDEX idx_customer_tax_regime ON customer (tax_regime_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_customer_company_number ON customer (company_id, number)');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT fk_customer_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT fk_customer_group FOREIGN KEY (customer_group_id) REFERENCES customer_group (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE customer ADD CONSTRAINT fk_customer_tax_regime FOREIGN KEY (tax_regime_id) REFERENCES customer_tax_regime (id) NOT DEFERRABLE');

        $this->addSql('CREATE TABLE contact (id UUID NOT NULL, company_id UUID NOT NULL, customer_id UUID NOT NULL, first_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) DEFAULT NULL, email VARCHAR(254) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, role VARCHAR(100) DEFAULT NULL, is_primary BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_contact_company ON contact (company_id)');
        $this->addSql('CREATE INDEX idx_contact_customer ON contact (customer_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_contact_primary ON contact (customer_id) WHERE is_primary');
        $this->addSql('ALTER TABLE contact ADD CONSTRAINT fk_contact_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE contact ADD CONSTRAINT fk_contact_customer FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE contact');
        $this->addSql('DROP TABLE customer');
        $this->addSql('DROP TABLE customer_group');
    }
}
