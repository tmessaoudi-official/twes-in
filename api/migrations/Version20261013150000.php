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
 * Its owner can close a company down: it is kept read-only until the last day its country's records must be kept,
 * fixed when it closed, and removed after it.
 */
final class Version20261013150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'When a company was closed down, and until when it is kept';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD closed_down_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE company ADD retain_until DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP retain_until');
        $this->addSql('ALTER TABLE company DROP closed_down_at');
    }
}
