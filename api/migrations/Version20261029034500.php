<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029034500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A closed company remembers when its owners were told its removal draws near.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD removal_told_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP removal_told_at');
    }
}
