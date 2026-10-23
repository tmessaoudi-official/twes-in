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
 * A delivery note line may name the line of the issued invoice whose goods it hands over: the invoice took them out of
 * stock and billed them, so such a line moves no stock and is never invoiced again.
 */
final class Version20261023070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A delivery note line may name the invoice line whose goods it delivers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note_line ADD source_invoice_line_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_delivery_note_line_source_invoice_line ON delivery_note_line (source_invoice_line_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_delivery_note_line_source_invoice_line');
        $this->addSql('ALTER TABLE delivery_note_line DROP source_invoice_line_id');
    }
}
