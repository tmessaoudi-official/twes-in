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
 * G10 inventory (docs/SPEC.md § 4 stock_location and stock_movement): a tree of locations per establishment with one
 * default each, and signed stock movements at NUMERIC(14,3), unique per source document, product, location and direction.
 */
final class Version20260915140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stock locations and stock movements.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stock_location (id UUID NOT NULL, company_id UUID NOT NULL, establishment_id UUID NOT NULL, parent_id UUID DEFAULT NULL, kind VARCHAR(16) NOT NULL, code VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, is_default BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_stock_location_company ON stock_location (company_id)');
        $this->addSql('CREATE INDEX idx_stock_location_parent ON stock_location (parent_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_location_establishment_code ON stock_location (establishment_id, code)');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_location_default ON stock_location (establishment_id) WHERE (is_default)');
        $this->addSql('ALTER TABLE stock_location ADD CONSTRAINT fk_stock_location_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_location ADD CONSTRAINT fk_stock_location_establishment FOREIGN KEY (establishment_id) REFERENCES establishment (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_location ADD CONSTRAINT fk_stock_location_parent FOREIGN KEY (parent_id) REFERENCES stock_location (id) NOT DEFERRABLE');

        $this->addSql('CREATE TABLE stock_movement (id UUID NOT NULL, company_id UUID NOT NULL, product_id UUID NOT NULL, location_id UUID NOT NULL, kind VARCHAR(16) NOT NULL, quantity NUMERIC(14, 3) NOT NULL, source_type VARCHAR(32) NOT NULL, source_id UUID DEFAULT NULL, recorded_by UUID DEFAULT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_stock_movement_company ON stock_movement (company_id)');
        $this->addSql('CREATE INDEX idx_stock_movement_product ON stock_movement (product_id)');
        $this->addSql('CREATE INDEX idx_stock_movement_location ON stock_movement (location_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_movement_source ON stock_movement (source_type, source_id, product_id, location_id, kind)');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT fk_stock_movement_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT fk_stock_movement_product FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT fk_stock_movement_location FOREIGN KEY (location_id) REFERENCES stock_location (id) NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stock_movement');
        $this->addSql('DROP TABLE stock_location');
    }
}
