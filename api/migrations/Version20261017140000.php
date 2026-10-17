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
 * The version Doctrine counts on a recurring invoice and a recurring expense, so a revision or a pause read before
 * another save, a run's draft included, is refused rather than writing over it. Every row starts at 1.
 */
final class Version20261017140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The version of a recurring invoice and of a recurring expense';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_invoice ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE recurring_expense ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_expense DROP version');
        $this->addSql('ALTER TABLE recurring_invoice DROP version');
    }
}
