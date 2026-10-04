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
 * A goods receipt says where the goods came from: an optional vendor, the number on the vendor's own delivery note or
 * invoice, and the day they arrived. Kept on the receipt's stock movement until the purchase module's goods receipt
 * exists, and migrated into it then. A vendor that is deleted leaves its receipts with none rather than taking them.
 */
final class Version20261004105832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vendor, supplier reference and arrival day on a goods receipt.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement ADD supplier_reference VARCHAR(60) DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD received_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD vendor_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5F603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_stock_movement_vendor ON stock_movement (vendor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement DROP CONSTRAINT FK_BB1BC1B5F603EE73');
        $this->addSql('DROP INDEX idx_stock_movement_vendor');
        $this->addSql('ALTER TABLE stock_movement DROP supplier_reference');
        $this->addSql('ALTER TABLE stock_movement DROP received_on');
        $this->addSql('ALTER TABLE stock_movement DROP vendor_id');
    }
}
