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
 * A product is bought from several vendors, with or without a code of theirs. Every supplier code already stored says
 * its product is bought from that vendor, so each becomes a link here: the product keeps every supplier code's vendor
 * linked, and that holds for the rows written before this table as for the ones after.
 */
final class Version20261020003452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Products are linked to the vendors they are bought from.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_vendor (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, product_id UUID NOT NULL, vendor_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_product_vendor_vendor ON product_vendor (vendor_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_vendor_pair ON product_vendor (product_id, vendor_id)');
        $this->addSql('CREATE INDEX IDX_975E7252979B1AD6 ON product_vendor (company_id)');
        $this->addSql('CREATE INDEX IDX_975E72524584665A ON product_vendor (product_id)');
        $this->addSql('ALTER TABLE product_vendor ADD CONSTRAINT FK_975E7252979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_vendor ADD CONSTRAINT FK_975E72524584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_vendor ADD CONSTRAINT FK_975E7252F603EE73 FOREIGN KEY (vendor_id) REFERENCES vendor (id) NOT DEFERRABLE');
        $this->addSql(<<<'SQL'
            INSERT INTO product_vendor (id, company_id, product_id, vendor_id, created_at)
            SELECT gen_random_uuid(), company_id, product_id, supplier_id, MIN(created_at)
            FROM product_barcode
            WHERE supplier_id IS NOT NULL
            GROUP BY company_id, product_id, supplier_id
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_vendor');
    }
}
