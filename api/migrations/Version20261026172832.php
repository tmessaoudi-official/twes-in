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
 * Who of the shop received a vendor's goods: the name as the receipt says it, and the member's account it was copied
 * from, with no foreign key (the name, not the account, is who received); and on the order each receipt's receiver
 * once, which its list shows and searches. The orders' trigram index is built again over that column: its expression
 * is the one DoctrinePurchaseOrderRepository::MATCHES_WORDS asks for, character for character.
 */
final class Version20261026172832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A goods receipt keeps who received it, and the orders list searches who received.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE goods_receipt ADD received_by_name VARCHAR(201) DEFAULT NULL');
        $this->addSql('ALTER TABLE goods_receipt ADD received_by_member_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE purchase_order ADD receivers TEXT DEFAULT NULL');
        $this->addSql('DROP INDEX idx_purchase_order_search');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_purchase_order_search ON purchase_order USING gin (search_text(number, vendor_name, receivers) gin_trgm_ops)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_purchase_order_search');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_purchase_order_search ON purchase_order USING gin (search_text(number, vendor_name) gin_trgm_ops)
            SQL);
        $this->addSql('ALTER TABLE purchase_order DROP receivers');
        $this->addSql('ALTER TABLE goods_receipt DROP received_by_member_id');
        $this->addSql('ALTER TABLE goods_receipt DROP received_by_name');
    }
}
