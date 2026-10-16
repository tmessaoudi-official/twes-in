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
 * A delivery note's delivery becomes a moment, kept in UTC like every other moment here. A day already written is read
 * as midnight on its company's clock, not UTC's, so it reads back as the same day: midnight UTC is already the next
 * day's 01:00 in Tunis, and the day before anywhere west of Greenwich. Going back keeps each moment's day on that
 * same clock.
 */
final class Version20261016040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A delivery note delivered at a moment';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note ALTER delivery_date TYPE TIMESTAMP(0) WITHOUT TIME ZONE');
        $this->addSql(<<<'SQL'
            UPDATE delivery_note n
               SET delivery_date = (n.delivery_date AT TIME ZONE c.timezone) AT TIME ZONE 'UTC'
              FROM company c
             WHERE c.id = n.company_id AND n.delivery_date IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE delivery_note n
               SET delivery_date = date_trunc('day', (n.delivery_date AT TIME ZONE 'UTC') AT TIME ZONE c.timezone)
              FROM company c
             WHERE c.id = n.company_id AND n.delivery_date IS NOT NULL
            SQL);
        $this->addSql('ALTER TABLE delivery_note ALTER delivery_date TYPE DATE');
    }
}
