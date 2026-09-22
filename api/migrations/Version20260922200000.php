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
 * A floor has a size of its own (docs/SPEC.md § 7, 2026-09-22, findings E and H).
 *
 * Without one the board framed whatever racks were saved: every save changed the frame and everything on it jumped,
 * a wall outside the racks' extent could fall off the board, and an empty floor was a blank sheet with no edge.
 *
 * Nullable, because the floors drawn before this have no size and nobody measured them for us; the application asks
 * for it on every write from now on. The check keeps the two sides together and both on the ground: a floor with one
 * side, or with none of either, is not a surface.
 */
final class Version20260922200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A floor carries its width and depth in metres.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_area ADD width_metres NUMERIC(9, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE venue_area ADD depth_metres NUMERIC(9, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE venue_area ADD CONSTRAINT venue_area_size CHECK ((width_metres IS NULL AND depth_metres IS NULL) OR (width_metres > 0 AND depth_metres > 0))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_area DROP CONSTRAINT venue_area_size');
        $this->addSql('ALTER TABLE venue_area DROP width_metres');
        $this->addSql('ALTER TABLE venue_area DROP depth_metres');
    }
}
