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
 * A product says how its stock is told apart: not at all, by lot, or one piece at a time by its serial number
 * (docs/SPEC.md § 7, 2026-09-23 02:40). Every existing product tracks nothing, and a service never tracks.
 */
final class Version20260923030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Products say whether their stock is tracked by lot or by serial number.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE product ADD tracking VARCHAR(8) DEFAULT 'none' NOT NULL");
        $this->addSql("ALTER TABLE product ADD CONSTRAINT product_tracking CHECK (tracking IN ('none', 'lot', 'serial') AND (kind = 'goods' OR tracking = 'none'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP CONSTRAINT product_tracking');
        $this->addSql('ALTER TABLE product DROP tracking');
    }
}
