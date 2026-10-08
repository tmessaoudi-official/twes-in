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
 * A product's photos: three stored files each (the original and the small and large copies), the original's upright
 * size, a place in the gallery and whether it is the main one. One main photo per product among those in the gallery,
 * held by a partial unique index that leaves out the removed ones, which are kept so a removal can be undone.
 */
final class Version20261008222506 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The photos of a product';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_photo (id UUID NOT NULL, width INT NOT NULL, height INT NOT NULL, position INT NOT NULL, is_main BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, removed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, company_id UUID NOT NULL, product_id UUID NOT NULL, original_file_id UUID NOT NULL, small_file_id UUID NOT NULL, large_file_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_product_photo_company ON product_photo (company_id)');
        $this->addSql('CREATE INDEX idx_product_photo_product ON product_photo (product_id, position)');
        $this->addSql('CREATE INDEX idx_product_photo_original ON product_photo (original_file_id)');
        $this->addSql('CREATE INDEX idx_product_photo_small ON product_photo (small_file_id)');
        $this->addSql('CREATE INDEX idx_product_photo_large ON product_photo (large_file_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_photo_main ON product_photo (product_id) WHERE (is_main AND (removed_at IS NULL))');
        $this->addSql('CREATE INDEX IDX_B5EBFF444584665A ON product_photo (product_id)');
        $this->addSql('ALTER TABLE product_photo ADD CONSTRAINT FK_B5EBFF44979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_photo ADD CONSTRAINT FK_B5EBFF444584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_photo ADD CONSTRAINT FK_B5EBFF44154BD79B FOREIGN KEY (original_file_id) REFERENCES file (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_photo ADD CONSTRAINT FK_B5EBFF44DCC0A3E0 FOREIGN KEY (small_file_id) REFERENCES file (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_photo ADD CONSTRAINT FK_B5EBFF4429EA72E8 FOREIGN KEY (large_file_id) REFERENCES file (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_photo ADD CONSTRAINT ck_product_photo_sizes CHECK (width > 0 AND height > 0 AND position >= 0)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_photo');
    }
}
