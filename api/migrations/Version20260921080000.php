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
 * Where a product normally lives, per establishment (docs/SPEC.md row 101).
 *
 * `establishment_id` is stored beside `location_id` although a location already knows its establishment, because it
 * is what the uniqueness is about: one home per product per establishment cannot be enforced over a column the
 * database has to join to find. The entity is the only way a row is made and keeps the two in step.
 */
final class Version20260921080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The location a product normally lives at, one per establishment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE product_home_location (
                id UUID NOT NULL,
                company_id UUID NOT NULL,
                product_id UUID NOT NULL,
                establishment_id UUID NOT NULL,
                location_id UUID NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                PRIMARY KEY (id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_product_home_company ON product_home_location (company_id)');
        $this->addSql('CREATE INDEX idx_product_home_location ON product_home_location (location_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_home_establishment ON product_home_location (product_id, establishment_id)');
        $this->addSql('ALTER TABLE product_home_location ADD CONSTRAINT fk_product_home_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_home_location ADD CONSTRAINT fk_product_home_product FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_home_location ADD CONSTRAINT fk_product_home_establishment FOREIGN KEY (establishment_id) REFERENCES establishment (id) ON DELETE CASCADE NOT DEFERRABLE');
        // A home whose shelf is gone is not a home. The location itself refuses to go while it holds stock or
        // children, so this cannot quietly lose a place goods are actually kept in.
        $this->addSql('ALTER TABLE product_home_location ADD CONSTRAINT fk_product_home_location FOREIGN KEY (location_id) REFERENCES stock_location (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_home_location');
    }
}
