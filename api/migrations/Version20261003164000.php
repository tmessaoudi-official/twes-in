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
 * Whether a receipt's cost was typed by somebody. A receipt saved without one is valued at the average, and the last
 * price a receipt screen offers must only ever be a cost that was typed. Receipts written before this have no mark.
 */
final class Version20261003164000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Whether a stock movement\'s cost was typed.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement ADD cost_typed BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP cost_typed');
    }
}
