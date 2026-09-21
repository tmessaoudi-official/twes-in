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
 * The drawing (docs/SPEC.md § 4 venue_area, venue_spot; § 7, 2026-09-14 and 2026-09-19 23:40): a floor per level of an
 * establishment, rectangles measured in metres on it, and the inventory's binding to one of them.
 *
 * `stock_location.spot_id` is nullable and drops to null when its rectangle goes, never cascading: a location that is
 * no longer drawn is still a location, holding its movements and its stock.
 */
final class Version20260921030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Venue areas and spots, and the stock location that is drawn at one.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_area (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                establishment_id UUID NOT NULL,
                name VARCHAR(120) NOT NULL,
                level INT NOT NULL,
                image_file_id UUID DEFAULT NULL,
                image_opacity INT DEFAULT 35 NOT NULL,
                image_metres_wide NUMERIC(9, 3) DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_area_company ON venue_area (company_id)');
        $this->addSql('CREATE INDEX idx_venue_area_establishment ON venue_area (establishment_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_venue_area_establishment_level ON venue_area (establishment_id, level)');
        $this->addSql('ALTER TABLE venue_area ADD CONSTRAINT fk_venue_area_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE venue_area ADD CONSTRAINT fk_venue_area_establishment FOREIGN KEY (establishment_id) REFERENCES establishment (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE venue_area ADD CONSTRAINT ck_venue_area_level CHECK (level BETWEEN 0 AND 200)');
        $this->addSql('ALTER TABLE venue_area ADD CONSTRAINT ck_venue_area_opacity CHECK (image_opacity BETWEEN 0 AND 100)');
        // A plan without its width in metres cannot be placed under rectangles measured in metres, so neither exists
        // without the other; the entity refuses the same pair, and this is what a direct write still meets.
        $this->addSql('ALTER TABLE venue_area ADD CONSTRAINT ck_venue_area_plan_scaled CHECK ((image_file_id IS NULL) = (image_metres_wide IS NULL))');

        $this->addSql(<<<'SQL'
            CREATE TABLE venue_spot (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                area_id UUID NOT NULL,
                plan_x NUMERIC(9, 3) NOT NULL,
                plan_y NUMERIC(9, 3) NOT NULL,
                plan_width NUMERIC(9, 3) NOT NULL,
                plan_depth NUMERIC(9, 3) NOT NULL,
                plan_rotation INT NOT NULL,
                plan_height NUMERIC(9, 3) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_venue_spot_company ON venue_spot (company_id)');
        $this->addSql('CREATE INDEX idx_venue_spot_area ON venue_spot (area_id)');
        $this->addSql('ALTER TABLE venue_spot ADD CONSTRAINT fk_venue_spot_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE venue_spot ADD CONSTRAINT fk_venue_spot_area FOREIGN KEY (area_id) REFERENCES venue_area (id) ON DELETE CASCADE NOT DEFERRABLE');
        // A rectangle with no surface is not a place, and a negative one draws itself backwards over its neighbour.
        $this->addSql('ALTER TABLE venue_spot ADD CONSTRAINT ck_venue_spot_surface CHECK (plan_width > 0 AND plan_depth > 0)');
        $this->addSql('ALTER TABLE venue_spot ADD CONSTRAINT ck_venue_spot_on_the_plan CHECK (plan_x >= 0 AND plan_y >= 0 AND plan_height >= 0)');
        $this->addSql('ALTER TABLE venue_spot ADD CONSTRAINT ck_venue_spot_rotation CHECK (plan_rotation BETWEEN 0 AND 359)');

        $this->addSql('ALTER TABLE stock_location ADD spot_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_stock_location_spot ON stock_location (spot_id)');
        $this->addSql('ALTER TABLE stock_location ADD CONSTRAINT fk_stock_location_spot FOREIGN KEY (spot_id) REFERENCES venue_spot (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_location DROP CONSTRAINT fk_stock_location_spot');
        $this->addSql('DROP INDEX idx_stock_location_spot');
        $this->addSql('ALTER TABLE stock_location DROP spot_id');
        $this->addSql('DROP TABLE venue_spot');
        $this->addSql('DROP TABLE venue_area');
    }
}
