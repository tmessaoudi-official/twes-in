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
 * A unit's and a tax's name in each other language documents print in. Null on the rows written before: the next
 * start gives them their preset's names once, by code, and leaves the name the company kept as it is.
 */
final class Version20261019020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Units and taxes named in each language documents print in.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unit ADD names JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE tax_component ADD names JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE unit DROP names');
        $this->addSql('ALTER TABLE tax_component DROP names');
    }
}
