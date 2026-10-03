<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** What a product cost the company over time: every change of its cost, with what moved it and who. */
final class Version20261003134050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The history of a product\'s cost.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_cost_change (id UUID NOT NULL, old_cost NUMERIC(14, 4) DEFAULT NULL, new_cost NUMERIC(14, 4) DEFAULT NULL, source VARCHAR(16) NOT NULL, source_id UUID DEFAULT NULL, changed_by UUID DEFAULT NULL, at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, product_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_product_cost_change_company ON product_cost_change (company_id)');
        $this->addSql('CREATE INDEX idx_product_cost_change_product ON product_cost_change (product_id)');
        $this->addSql('ALTER TABLE product_cost_change ADD CONSTRAINT FK_EB4E2690979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE product_cost_change ADD CONSTRAINT FK_EB4E26904584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_cost_change');
    }
}
