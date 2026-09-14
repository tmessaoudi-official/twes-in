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
 * G5 products module: product categories, a tree within a company, and products with their unit, category, prices at
 * NUMERIC(14,4), default line taxes and custom field values.
 */
final class Version20260914160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Products and product categories.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_category (id UUID NOT NULL, company_id UUID NOT NULL, name VARCHAR(120) NOT NULL, parent_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_product_category_company ON product_category (company_id)');
        $this->addSql('CREATE INDEX idx_product_category_parent ON product_category (parent_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_category_company_name ON product_category (company_id, name)');
        $this->addSql('ALTER TABLE product_category ADD CONSTRAINT fk_product_category_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_category ADD CONSTRAINT fk_product_category_parent FOREIGN KEY (parent_id) REFERENCES product_category (id) NOT DEFERRABLE');

        $this->addSql('CREATE TABLE product (id UUID NOT NULL, company_id UUID NOT NULL, reference VARCHAR(32) NOT NULL, name VARCHAR(200) NOT NULL, description TEXT DEFAULT NULL, kind VARCHAR(16) NOT NULL, unit_id UUID NOT NULL, unit_price_net NUMERIC(14, 4) NOT NULL, cost_price NUMERIC(14, 4) DEFAULT NULL, category_id UUID DEFAULT NULL, barcode VARCHAR(64) DEFAULT NULL, default_tax_component_ids JSONB DEFAULT \'[]\' NOT NULL, custom_fields JSONB DEFAULT \'{}\' NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_product_company ON product (company_id)');
        $this->addSql('CREATE INDEX idx_product_unit ON product (unit_id)');
        $this->addSql('CREATE INDEX idx_product_category ON product (category_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_company_reference ON product (company_id, reference)');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT fk_product_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT fk_product_unit FOREIGN KEY (unit_id) REFERENCES unit (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT fk_product_category FOREIGN KEY (category_id) REFERENCES product_category (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_category');
    }
}
