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
 * G8 vendors (docs/SPEC.md § 4 vendor): the people a company buys from, numbered uniquely in the company. The default
 * expense category arrives with the expense categories, in G9.
 */
final class Version20260915150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vendors.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE vendor (id UUID NOT NULL, company_id UUID NOT NULL, number VARCHAR(32) NOT NULL, name VARCHAR(200) NOT NULL, legal_name VARCHAR(200) DEFAULT NULL, identifiers JSONB DEFAULT '{}' NOT NULL, email VARCHAR(254) DEFAULT NULL, phone VARCHAR(40) DEFAULT NULL, website VARCHAR(200) DEFAULT NULL, iban VARCHAR(34) DEFAULT NULL, bic VARCHAR(11) DEFAULT NULL, payment_terms_days SMALLINT DEFAULT NULL, notes TEXT DEFAULT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, address_line1 VARCHAR(200) DEFAULT NULL, address_line2 VARCHAR(200) DEFAULT NULL, address_postal_code VARCHAR(20) DEFAULT NULL, address_city VARCHAR(120) DEFAULT NULL, address_country_code VARCHAR(2) DEFAULT NULL, PRIMARY KEY (id))");
        $this->addSql('CREATE INDEX idx_vendor_company ON vendor (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_vendor_company_number ON vendor (company_id, number)');
        $this->addSql('ALTER TABLE vendor ADD CONSTRAINT fk_vendor_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE vendor');
    }
}
