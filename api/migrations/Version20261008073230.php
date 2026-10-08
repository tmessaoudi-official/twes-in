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
 * What an issued invoice's discounts took off, written at issue with its other figures, so its « Vous économisez » line
 * prints what it said that day. A document issued before has none, and prints none.
 */
final class Version20261008073230 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'What an issued invoice\'s discounts took off';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD savings NUMERIC(14, 3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP savings');
    }
}
