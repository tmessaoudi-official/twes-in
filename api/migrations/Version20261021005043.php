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
 * A product keeps the cost its price was set against while a receipt's cost moved under the price, until someone adjusts
 * the price or keeps it: the margin never moves without a word.
 */
final class Version20261021005043 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A product keeps the cost its price was set against while a moved margin waits for a review.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD price_review_cost NUMERIC(14, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP price_review_cost');
    }
}
