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
 * A product's counts at a place, in the order they were made: « Sorties sans stock » looks, for each sale that left its
 * place short, for a count there after it; read among every movement of the place, that took seconds once a few best
 * sellers carried thousands of movements each. Counts are few, so the index is small.
 */
final class Version20261028230958 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A product\'s counts at a place are found by their moment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX idx_stock_movement_counts ON stock_movement (product_id, location_id, at) WHERE (source_type = 'count')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_stock_movement_counts');
    }
}
