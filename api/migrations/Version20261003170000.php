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
 * Goods a credit note returns to stock. A credit note's line says whether its goods came back, and the movement that
 * brings them back names the invoice whose sale it takes back, so what an invoice has already had returned can be
 * counted before a later credit note returns more.
 */
final class Version20261003170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A line\'s returned flag, and the invoice a returning stock movement reverses.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line ADD returned BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD reverses_source_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_stock_movement_reverses ON stock_movement (reverses_source_id) WHERE (reverses_source_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_stock_movement_reverses');
        $this->addSql('ALTER TABLE stock_movement DROP reverses_source_id');
        $this->addSql('ALTER TABLE invoice_line DROP returned');
    }
}
