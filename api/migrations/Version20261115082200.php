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
 * Each recorded session keeps the companies it entered, so a company's owner sees and ends a member's sessions that
 * have been inside it and no other. A session recorded before has none until its next request notes them; the release
 * before this one reads no such column.
 */
final class Version20261115082200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The companies each recorded session entered.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_session ADD companies JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_session DROP companies');
    }
}
