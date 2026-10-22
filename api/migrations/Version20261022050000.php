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
 * A product whose price taxes included, read in from a file, no net price reaches: it was kept at the nearest, and the
 * product stays « À vérifier » for it until a price is set again.
 */
final class Version20261022050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'product.price_not_reached: an imported price taxes included kept at the nearest one reached.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD price_not_reached BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP price_not_reached');
    }
}
