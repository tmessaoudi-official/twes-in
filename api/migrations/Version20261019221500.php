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
 * A product found by its name whatever its case, as an import asks for each new product: without it the company's whole
 * catalogue is read for every row of the file. Built on the very expression the query repeats (`LOWER(name)`), and on it
 * alone: the company is a filter over the few products of that name, and an index made only of an expression is one the
 * schema tool does not see, like the search indexes, where one beginning with a column would be proposed for dropping.
 */
final class Version20261019221500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Products are found by their name through an index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_product_name ON product (LOWER(name))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_product_name');
    }
}
