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
 * Orders to vendors, their lines, and the receipts their goods arrive in, line by line; the checks no amount or
 * quantity may pass (an order line never received beyond what it ordered); and the trigram index an orders list is
 * searched through. Its expression is the one DoctrinePurchaseOrderRepository::MATCHES_WORDS asks for, character for
 * character: PostgreSQL uses the index only when the query repeats it exactly.
 */
final class Version20261018140418 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Purchase orders, their lines and their goods receipts, and the trigram index they are searched with.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE goods_receipt (id UUID NOT NULL, received_on DATE NOT NULL, supplier_reference VARCHAR(60) DEFAULT NULL, recorded_by UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, purchase_order_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_goods_receipt_order ON goods_receipt (purchase_order_id)');
        $this->addSql('CREATE INDEX idx_goods_receipt_company ON goods_receipt (company_id)');
        $this->addSql('CREATE TABLE goods_receipt_line (id UUID NOT NULL, position INT NOT NULL, location_id UUID NOT NULL, quantity NUMERIC(14, 3) NOT NULL, lot_code VARCHAR(40) DEFAULT NULL, lot_expires_on DATE DEFAULT NULL, unit_cost NUMERIC(14, 4) DEFAULT NULL, goods_receipt_id UUID NOT NULL, company_id UUID NOT NULL, purchase_order_line_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_goods_receipt_line_receipt ON goods_receipt_line (goods_receipt_id)');
        $this->addSql('CREATE INDEX idx_goods_receipt_line_company ON goods_receipt_line (company_id)');
        $this->addSql('CREATE INDEX idx_goods_receipt_line_order_line ON goods_receipt_line (purchase_order_line_id)');
        $this->addSql('CREATE INDEX idx_goods_receipt_line_location ON goods_receipt_line (location_id)');
        $this->addSql('CREATE TABLE purchase_order (id UUID NOT NULL, vendor_id UUID NOT NULL, vendor_name VARCHAR(255) NOT NULL, status VARCHAR(20) NOT NULL, number VARCHAR(64) DEFAULT NULL, issue_date DATE DEFAULT NULL, expected_on DATE DEFAULT NULL, remarks_printed TEXT DEFAULT NULL, notes_internal TEXT DEFAULT NULL, vendor_snapshot JSONB DEFAULT NULL, buyer_snapshot JSONB DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT DEFAULT 1 NOT NULL, company_id UUID NOT NULL, establishment_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_purchase_order_company ON purchase_order (company_id)');
        $this->addSql('CREATE INDEX idx_purchase_order_establishment ON purchase_order (establishment_id)');
        $this->addSql('CREATE INDEX idx_purchase_order_vendor ON purchase_order (vendor_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_order_company_number ON purchase_order (company_id, number)');
        $this->addSql('CREATE TABLE purchase_order_line (id UUID NOT NULL, position INT NOT NULL, product_id UUID NOT NULL, product_reference VARCHAR(64) NOT NULL, description TEXT NOT NULL, quantity NUMERIC(14, 3) NOT NULL, unit_price_net NUMERIC(14, 4) DEFAULT NULL, received NUMERIC(14, 3) DEFAULT \'0.000\' NOT NULL, purchase_order_id UUID NOT NULL, company_id UUID NOT NULL, unit_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_purchase_order_line_order ON purchase_order_line (purchase_order_id)');
        $this->addSql('CREATE INDEX idx_purchase_order_line_company ON purchase_order_line (company_id)');
        $this->addSql('CREATE INDEX idx_purchase_order_line_product ON purchase_order_line (product_id)');
        $this->addSql('CREATE INDEX idx_purchase_order_line_unit ON purchase_order_line (unit_id)');
        $this->addSql('ALTER TABLE goods_receipt ADD CONSTRAINT FK_B6CFD848979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE goods_receipt ADD CONSTRAINT FK_B6CFD848A45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE goods_receipt_line ADD CONSTRAINT FK_2F3E9E505AF289C8 FOREIGN KEY (goods_receipt_id) REFERENCES goods_receipt (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE goods_receipt_line ADD CONSTRAINT FK_2F3E9E50979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE goods_receipt_line ADD CONSTRAINT FK_2F3E9E506D136516 FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_line (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_order ADD CONSTRAINT FK_21E210B2979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_order ADD CONSTRAINT FK_21E210B28565851 FOREIGN KEY (establishment_id) REFERENCES establishment (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_order_line ADD CONSTRAINT FK_90D6D92BA45D7E6A FOREIGN KEY (purchase_order_id) REFERENCES purchase_order (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_order_line ADD CONSTRAINT FK_90D6D92B979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_order_line ADD CONSTRAINT FK_90D6D92BF8BD700D FOREIGN KEY (unit_id) REFERENCES unit (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE purchase_order_line ADD CONSTRAINT chk_purchase_order_line_values CHECK (quantity > 0 AND received >= 0 AND received <= quantity AND (unit_price_net IS NULL OR unit_price_net >= 0))');
        $this->addSql('ALTER TABLE goods_receipt_line ADD CONSTRAINT chk_goods_receipt_line_values CHECK (quantity > 0 AND (unit_cost IS NULL OR unit_cost >= 0) AND (lot_expires_on IS NULL OR lot_code IS NOT NULL))');
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_purchase_order_search ON purchase_order USING gin (search_text(number, vendor_name) gin_trgm_ops)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_purchase_order_search');
        $this->addSql('ALTER TABLE goods_receipt DROP CONSTRAINT FK_B6CFD848979B1AD6');
        $this->addSql('ALTER TABLE goods_receipt DROP CONSTRAINT FK_B6CFD848A45D7E6A');
        $this->addSql('ALTER TABLE goods_receipt_line DROP CONSTRAINT FK_2F3E9E505AF289C8');
        $this->addSql('ALTER TABLE goods_receipt_line DROP CONSTRAINT FK_2F3E9E50979B1AD6');
        $this->addSql('ALTER TABLE goods_receipt_line DROP CONSTRAINT FK_2F3E9E506D136516');
        $this->addSql('ALTER TABLE purchase_order DROP CONSTRAINT FK_21E210B2979B1AD6');
        $this->addSql('ALTER TABLE purchase_order DROP CONSTRAINT FK_21E210B28565851');
        $this->addSql('ALTER TABLE purchase_order_line DROP CONSTRAINT FK_90D6D92BA45D7E6A');
        $this->addSql('ALTER TABLE purchase_order_line DROP CONSTRAINT FK_90D6D92B979B1AD6');
        $this->addSql('ALTER TABLE purchase_order_line DROP CONSTRAINT FK_90D6D92BF8BD700D');
        $this->addSql('DROP TABLE goods_receipt');
        $this->addSql('DROP TABLE goods_receipt_line');
        $this->addSql('DROP TABLE purchase_order');
        $this->addSql('DROP TABLE purchase_order_line');
    }
}
