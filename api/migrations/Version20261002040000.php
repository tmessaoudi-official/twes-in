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
 * The group of products that replace one another (docs/SPEC.md § 7), carried by the products themselves. The index
 * narrows a company's products to the ones that carry a group.
 */
final class Version20261002040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A product\'s substitution group.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD substitution_group VARCHAR(80) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_product_substitution_group ON product (company_id, substitution_group)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_product_substitution_group');
        $this->addSql('ALTER TABLE product DROP substitution_group');
    }
}
