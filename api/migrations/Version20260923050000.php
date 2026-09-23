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
 * Deliveries take their goods from lots (docs/SPEC.md § 7, 2026-09-23 02:40, L2), so one delivery note moves a product
 * from a location once per LOT: the source key takes the lot. PostgreSQL counts NULLs as distinct in a unique index,
 * and an untracked product's movements name no lot, so the key is `NULLS NOT DISTINCT` — without it, the same note's
 * untracked movement written twice would no longer collide. It is partial, over movements that have a source id,
 * because a receipt or a count has none: with NULLs not distinct, two receipts of one product at one location would
 * otherwise collide as the same row. Doctrine maps the key and its predicate; the NULLS clause is the migration's.
 *
 * An expired lot is released by a person, and the lot keeps who and when.
 */
final class Version20260923050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stock lots can be released after their date; a delivery note moves each lot once.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_lot ADD released_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_lot ADD released_by UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_lot ADD CONSTRAINT stock_lot_released CHECK ((released_at IS NULL) = (released_by IS NULL))');
        $this->addSql('DROP INDEX uniq_stock_movement_source');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_movement_source ON stock_movement (source_type, source_id, product_id, location_id, kind, lot_id) NULLS NOT DISTINCT WHERE (source_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_stock_movement_source');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_movement_source ON stock_movement (source_type, source_id, product_id, location_id, kind)');
        $this->addSql('ALTER TABLE stock_lot DROP CONSTRAINT stock_lot_released');
        $this->addSql('ALTER TABLE stock_lot DROP released_by');
        $this->addSql('ALTER TABLE stock_lot DROP released_at');
    }
}
