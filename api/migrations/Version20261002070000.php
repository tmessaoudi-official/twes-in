<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Price lists and their prices (docs/SPEC.md § 7, 2026-09-20): a product's unit price from a quantity, for everyone, a group or a customer. */
final class Version20261002070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Price lists and their prices.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE price_list (id UUID NOT NULL, name VARCHAR(120) NOT NULL, customer_group_id UUID DEFAULT NULL, customer_id UUID DEFAULT NULL, valid_from DATE DEFAULT NULL, valid_to DATE DEFAULT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_price_list_company ON price_list (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_price_list_company_name ON price_list (company_id, name)');
        $this->addSql('CREATE TABLE price_list_item (id UUID NOT NULL, min_quantity NUMERIC(14, 3) NOT NULL, unit_price_net NUMERIC(14, 4) NOT NULL, company_id UUID NOT NULL, price_list_id UUID NOT NULL, product_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_price_list_item_product ON price_list_item (product_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_price_list_item_break ON price_list_item (price_list_id, product_id, min_quantity)');
        $this->addSql('CREATE INDEX IDX_D964C90B979B1AD6 ON price_list_item (company_id)');
        $this->addSql('CREATE INDEX IDX_D964C90B5688DED7 ON price_list_item (price_list_id)');
        $this->addSql('ALTER TABLE price_list ADD CONSTRAINT FK_399A0AA2979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE price_list_item ADD CONSTRAINT FK_D964C90B979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE price_list_item ADD CONSTRAINT FK_D964C90B5688DED7 FOREIGN KEY (price_list_id) REFERENCES price_list (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE price_list_item ADD CONSTRAINT FK_D964C90B4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE price_list ADD CONSTRAINT chk_price_list_scope CHECK (customer_group_id IS NULL OR customer_id IS NULL)');
        $this->addSql('ALTER TABLE price_list ADD CONSTRAINT chk_price_list_days CHECK (valid_from IS NULL OR valid_to IS NULL OR valid_to >= valid_from)');
        $this->addSql('ALTER TABLE price_list_item ADD CONSTRAINT chk_price_list_item_values CHECK (min_quantity > 0 AND unit_price_net >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE price_list DROP CONSTRAINT FK_399A0AA2979B1AD6');
        $this->addSql('ALTER TABLE price_list_item DROP CONSTRAINT FK_D964C90B979B1AD6');
        $this->addSql('ALTER TABLE price_list_item DROP CONSTRAINT FK_D964C90B5688DED7');
        $this->addSql('ALTER TABLE price_list_item DROP CONSTRAINT FK_D964C90B4584665A');
        $this->addSql('DROP TABLE price_list');
        $this->addSql('DROP TABLE price_list_item');
    }
}
