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
 * A delivery note line may name the line of the accepted quote it delivers, so the quote tracks what is delivered and
 * what is left, and the invoice of the note bills the quote's discount.
 */
final class Version20261023040500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A delivery note line may name the quote line it delivers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note_line ADD source_quote_line_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_delivery_note_line_source_quote_line ON delivery_note_line (source_quote_line_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_delivery_note_line_source_quote_line');
        $this->addSql('ALTER TABLE delivery_note_line DROP source_quote_line_id');
    }
}
