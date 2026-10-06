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
 * A receipt recorded by someone who may not read costs keeps its cost « à compléter » until a cost reader enters it
 * (docs/SPEC.md § 7, audit 2026-10-06 C challenge 9). The receipts already recorded that way cannot be told apart from a
 * cost reader's receipt without a cost, so they stay as they are.
 */
final class Version20261006110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A receipt says whether its cost is still to be entered by a cost reader.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement ADD cost_to_complete BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP cost_to_complete');
    }
}
