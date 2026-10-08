<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The last day of a company's closed period: no document is dated on or before it. Null while nothing is closed. */
final class Version20261008035602 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The last day of a company\'s closed period';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD closed_through DATE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP closed_through');
    }
}
