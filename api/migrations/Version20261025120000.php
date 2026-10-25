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
 * A shop's past purchases kept in each product's cost history: the vendor a line was bought from, its day when known,
 * its invoice and its quantity. Every column may be empty, as a change of the cost itself has none of them.
 */
final class Version20261025120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Past purchases in a product\'s cost history: vendor, day, invoice and quantity.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_cost_change ADD vendor_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE product_cost_change ADD purchased_on DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE product_cost_change ADD invoice_number VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE product_cost_change ADD quantity NUMERIC(14, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE product_cost_change ADD CONSTRAINT FK_PRODUCT_COST_CHANGE_VENDOR FOREIGN KEY (vendor_id) REFERENCES vendor (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_product_cost_change_vendor ON product_cost_change (vendor_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_product_cost_change_vendor');
        $this->addSql('ALTER TABLE product_cost_change DROP CONSTRAINT FK_PRODUCT_COST_CHANGE_VENDOR');
        $this->addSql('ALTER TABLE product_cost_change DROP quantity');
        $this->addSql('ALTER TABLE product_cost_change DROP invoice_number');
        $this->addSql('ALTER TABLE product_cost_change DROP purchased_on');
        $this->addSql('ALTER TABLE product_cost_change DROP vendor_id');
    }
}
