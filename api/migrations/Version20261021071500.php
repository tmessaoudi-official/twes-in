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
 * « À vérifier » on a product: the mark a person sets with its note, and what no other column holds of what it lacks: a
 * price of nothing meant, a unit a file did not name, a stock nobody counted yet. The other reasons are read from the
 * product as it is, never stored.
 */
final class Version20261021071500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A product carries « À vérifier », its note, a meant price of nothing, a unit to confirm and a count awaited.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD to_check BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE product ADD check_note VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD zero_price_intended BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE product ADD unit_to_confirm BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE product ADD to_count BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP to_check');
        $this->addSql('ALTER TABLE product DROP check_note');
        $this->addSql('ALTER TABLE product DROP zero_price_intended');
        $this->addSql('ALTER TABLE product DROP unit_to_confirm');
        $this->addSql('ALTER TABLE product DROP to_count');
    }
}
