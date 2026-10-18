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
 * The vendor's own code for each order line's product, read from the product's codes when the draft is saved and when
 * the order is sent. Empty on the lines written before: they are read again on their order's next save.
 */
final class Version20261018195646 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The vendor\'s own code on each purchase order line.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_order_line ADD vendor_code VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE purchase_order_line DROP vendor_code');
    }
}
