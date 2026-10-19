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
 * What an invoice, a delivery note and a quote called their units and the company's taxes the day they were issued,
 * validated or sent. Null on the rows written before: those print the names as they are, as they always did.
 */
final class Version20261019140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documents keep their unit and tax names as issued.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD printed_names JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_note ADD printed_names JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE quote ADD printed_names JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP printed_names');
        $this->addSql('ALTER TABLE delivery_note DROP printed_names');
        $this->addSql('ALTER TABLE quote DROP printed_names');
    }
}
