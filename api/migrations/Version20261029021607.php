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
 * A company's movements in the order the list shows them, newest first: its page is read from the index rather than by
 * sorting every movement the company ever made, and its count from the index alone.
 */
final class Version20261029021607 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A company\'s stock movements are found by their moment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_stock_movement_moved ON stock_movement (company_id, at, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_stock_movement_moved');
    }
}
