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
 * A product merged into another stays, pointing at it, so the issued documents and movements naming it keep naming it
 * while lists and pickers show the product kept.
 */
final class Version20261020091033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A product merged away points at the product kept.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD merged_into_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD285E57BA FOREIGN KEY (merged_into_id) REFERENCES product (id) NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT chk_product_not_merged_into_itself CHECK (merged_into_id IS DISTINCT FROM id)');
        $this->addSql('CREATE INDEX IDX_D34A04AD285E57BA ON product (merged_into_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP CONSTRAINT chk_product_not_merged_into_itself');
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04AD285E57BA');
        $this->addSql('DROP INDEX IDX_D34A04AD285E57BA');
        $this->addSql('ALTER TABLE product DROP merged_into_id');
    }
}
