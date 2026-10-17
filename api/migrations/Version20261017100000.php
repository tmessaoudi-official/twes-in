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
 * The version Doctrine counts on a drawn floor and on a piece of its structure, so a save of an older version than the
 * one stored is refused rather than overwriting what someone else saved in between. Every row starts at 1.
 */
final class Version20261017100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The version of a floor and of a piece of structure';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_area ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE venue_structure ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_structure DROP version');
        $this->addSql('ALTER TABLE venue_area DROP version');
    }
}
