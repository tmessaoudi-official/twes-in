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
 * A reorder point per product per establishment (docs/SPEC.md § 7, 2026-09-24 11:40): one row where a product has
 * one, none meaning no alert there.
 */
final class Version20260925060500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The quantity at or under which a product is reordered in an establishment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_reorder_point (id UUID NOT NULL, quantity NUMERIC(14, 3) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, product_id UUID NOT NULL, establishment_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_product_reorder_point_company ON product_reorder_point (company_id)');
        $this->addSql('CREATE INDEX idx_product_reorder_point_establishment ON product_reorder_point (establishment_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_reorder_point ON product_reorder_point (product_id, establishment_id)');
        $this->addSql('CREATE INDEX IDX_E07CBE924584665A ON product_reorder_point (product_id)');
        $this->addSql('ALTER TABLE product_reorder_point ADD CONSTRAINT FK_E07CBE92979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_reorder_point ADD CONSTRAINT FK_E07CBE924584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_reorder_point ADD CONSTRAINT FK_E07CBE928565851 FOREIGN KEY (establishment_id) REFERENCES establishment (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_reorder_point DROP CONSTRAINT FK_E07CBE92979B1AD6');
        $this->addSql('ALTER TABLE product_reorder_point DROP CONSTRAINT FK_E07CBE924584665A');
        $this->addSql('ALTER TABLE product_reorder_point DROP CONSTRAINT FK_E07CBE928565851');
        $this->addSql('DROP TABLE product_reorder_point');
    }
}
