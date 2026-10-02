<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** What a unit was valued at when stock moved (docs/SPEC.md § 7): the stock's value is the sum of quantity times it. */
final class Version20261002050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A stock movement\'s unit cost.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement ADD unit_cost NUMERIC(15, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP unit_cost');
    }
}
