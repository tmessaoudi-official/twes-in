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
 * The building drawn on a floor (docs/SPEC.md row 83, the approved canvas's Structure board): walls, doors, posts
 * and docks.
 *
 * Its own table and not `venue_spot`, because a spot exists to be bound to and nothing binds itself to a wall. Were
 * the building drawn as stock locations, every wall would sit in every stock list, every import and every
 * movement's location picker, holding nothing for as long as the company exists.
 *
 * The same rectangle discipline as `venue_spot`, checked here as well as in the entity, so a direct write meets it.
 */
final class Version20260922010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The structure layer: walls, doors, posts and docks drawn on a floor.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE venue_structure (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                area_id UUID NOT NULL,
                kind VARCHAR(16) NOT NULL,
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
        $this->addSql('CREATE INDEX idx_venue_structure_company ON venue_structure (company_id)');
        $this->addSql('CREATE INDEX idx_venue_structure_area ON venue_structure (area_id)');
        $this->addSql('ALTER TABLE venue_structure ADD CONSTRAINT fk_venue_structure_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE venue_structure ADD CONSTRAINT fk_venue_structure_area FOREIGN KEY (area_id) REFERENCES venue_area (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE venue_structure ADD CONSTRAINT ck_venue_structure_kind CHECK (kind IN ('wall', 'door', 'post', 'dock'))");
        // A piece with no surface is not a piece, and a negative one draws itself backwards over its neighbour.
        $this->addSql('ALTER TABLE venue_structure ADD CONSTRAINT ck_venue_structure_surface CHECK (plan_width > 0 AND plan_depth > 0)');
        $this->addSql('ALTER TABLE venue_structure ADD CONSTRAINT ck_venue_structure_on_the_plan CHECK (plan_x >= 0 AND plan_y >= 0 AND plan_height >= 0)');
        $this->addSql('ALTER TABLE venue_structure ADD CONSTRAINT ck_venue_structure_rotation CHECK (plan_rotation BETWEEN 0 AND 359)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE venue_structure');
    }
}
