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
 * The version Doctrine counts on a product, so a save of an older version than the one stored is refused rather than
 * overwriting what someone else saved in between. Every product starts at 1.
 */
final class Version20261016080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The version of a product';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP version');
    }
}
