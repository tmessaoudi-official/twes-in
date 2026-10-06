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
 * An opening value for the stock held before movements carried a cost (docs/SPEC.md § 7, audit E-6): without one, the
 * first sale after it was the only valued movement and the product read as worth less than nothing. Every movement with
 * no cost, of a product that has a cost price, is valued at that price; nobody typed it, so `cost_typed` stays false.
 * A product with no cost price stays unvalued, and its stock is estimated where the valuation reads it.
 *
 * Nothing records which movements had no cost, so going down leaves the values as they are.
 */
final class Version20261006090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An opening value for the stock held before it was valued.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE stock_movement m SET unit_cost = p.cost_price
            FROM product p
            WHERE p.id = m.product_id AND m.unit_cost IS NULL AND p.cost_price IS NOT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
    }
}
