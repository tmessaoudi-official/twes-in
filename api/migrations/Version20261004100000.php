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
 * A product may be kept at several places of one establishment, in order, the first being its main home. The
 * uniqueness moves from « one per establishment » to « one per place »; the order is a position that is rewritten for
 * the whole list at once, so it is not unique in the database (a swap of two would collide on its first update).
 */
final class Version20261004100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Several ordered homes per product and establishment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_product_home_establishment');
        $this->addSql('ALTER TABLE product_home_location ADD position SMALLINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE product_home_location ALTER position DROP DEFAULT');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_home_place ON product_home_location (product_id, location_id)');
        $this->addSql('CREATE INDEX idx_product_home_order ON product_home_location (product_id, establishment_id, position)');
    }

    public function down(Schema $schema): void
    {
        // Back to one home per establishment: the main one, the lowest position, is the one kept.
        $this->addSql('DELETE FROM product_home_location h USING product_home_location main
            WHERE h.product_id = main.product_id AND h.establishment_id = main.establishment_id
              AND (h.position, h.id) > (main.position, main.id)');
        $this->addSql('DROP INDEX idx_product_home_order');
        $this->addSql('DROP INDEX uniq_product_home_place');
        $this->addSql('ALTER TABLE product_home_location DROP position');
        $this->addSql('CREATE UNIQUE INDEX uniq_product_home_establishment ON product_home_location (product_id, establishment_id)');
    }
}
