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
 * An invoice's or credit note's sections: the title a line carries opens one, which runs to the next titled line. Every
 * line written before has none, so no document changes.
 */
final class Version20261009171401 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The title of the section an invoice line opens';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line ADD section VARCHAR(120) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line DROP section');
    }
}
