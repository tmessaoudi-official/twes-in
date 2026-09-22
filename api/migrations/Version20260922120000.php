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
 * A piece of the building carries the name the store already uses for it (docs/SPEC.md § 7, 2026-09-22).
 *
 * A store arrives having numbered its own building — "la porte du quai 2", "le mur nord" — and until now a wall,
 * a door, a post or a dock held a kind and a rectangle and nothing else, so none of that could be written down.
 * A stock location has carried both a code and a name from the start; the layer drawn beside it had neither.
 *
 * NOT NULL with an empty default, not a nullable column: most walls are just walls, and "no name" and "named the
 * empty string" are the same fact here — two spellings of it would mean every reader choosing between them.
 */
final class Version20260922120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A piece of structure carries a name.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE venue_structure ADD name VARCHAR(120) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE venue_structure DROP name');
    }
}
