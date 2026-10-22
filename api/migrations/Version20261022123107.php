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
 * A product keeps the price a cost moved away when the price followed it, while the price is the one the follow set:
 * « Annuler » puts back that price and no other.
 */
final class Version20261022123107 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A product keeps the price its cost moved away, for « Annuler ».';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD price_followed_from NUMERIC(14, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP price_followed_from');
    }
}
