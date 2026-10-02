<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Why goods were written off, and a note beside it (docs/SPEC.md § 7): a loss is told from a count by its reason. */
final class Version20261002060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A stock movement\'s loss reason and note.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement ADD reason VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD note VARCHAR(500) DEFAULT NULL');
        $this->addSql("ALTER TABLE stock_movement ADD CONSTRAINT chk_stock_movement_reason CHECK (reason IS NULL OR (source_type = 'loss' AND reason IN ('lost', 'broken', 'expired', 'stolen', 'internal_use', 'sample')))");
        $this->addSql("ALTER TABLE stock_movement ADD CONSTRAINT chk_stock_movement_loss_reason CHECK (source_type <> 'loss' OR reason IS NOT NULL)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT chk_stock_movement_loss_reason');
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT chk_stock_movement_reason');
        $this->addSql('ALTER TABLE stock_movement DROP note');
        $this->addSql('ALTER TABLE stock_movement DROP reason');
    }
}
