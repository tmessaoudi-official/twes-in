<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** What goods coming in to fill a stock below nothing add to its worth beyond quantity times cost (RunningValue). */
final class Version20261006090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A stock movement\'s revaluation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement ADD revaluation NUMERIC(30, 7) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP revaluation');
    }
}
