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
 * Stock kept by lot and by serial number (docs/SPEC.md § 7, 2026-09-23 02:40): a lot of its own, and the lot a
 * movement moved. Every existing movement names none, since every product tracked nothing until now.
 *
 * `uniq_stock_movement_source` is left as it is. PostgreSQL counts NULLs as distinct in a unique index, so adding the
 * nullable lot to that key would stop it holding a delivery note's movements once each for every untracked product;
 * deliveries write no lot until they pick them, and that step changes the key with `NULLS NOT DISTINCT`.
 */
final class Version20260923040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stock lots, and the lot each movement moved.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE stock_lot (id UUID NOT NULL, code VARCHAR(40) NOT NULL, expires_on DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, product_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_stock_lot_company ON stock_lot (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_stock_lot_code ON stock_lot (product_id, code)');
        $this->addSql('CREATE INDEX IDX_7DEF9C8D4584665A ON stock_lot (product_id)');
        $this->addSql('ALTER TABLE stock_lot ADD CONSTRAINT FK_7DEF9C8D979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE stock_lot ADD CONSTRAINT FK_7DEF9C8D4584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE');
        $this->addSql("ALTER TABLE stock_lot ADD CONSTRAINT stock_lot_code CHECK (code ~ '^[!-~]{1,40}$')");
        $this->addSql('ALTER TABLE stock_movement ADD lot_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5A8CBA5F7 FOREIGN KEY (lot_id) REFERENCES stock_lot (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_stock_movement_lot ON stock_movement (lot_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT FK_BB1BC1B5A8CBA5F7');
        $this->addSql('DROP INDEX idx_stock_movement_lot');
        $this->addSql('ALTER TABLE stock_movement DROP lot_id');
        $this->addSql('DROP TABLE stock_lot');
    }
}
