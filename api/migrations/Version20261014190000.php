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
 * The invoice line a credit note's line gives back, so a discount given as an amount is given back in shares. An id,
 * not a foreign key: a credit note's lines are written again on every revision, and the invoice's never are.
 */
final class Version20261014190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The invoice line a credit note line gives back.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line ADD credited_line_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_invoice_line_credited_line ON invoice_line (credited_line_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_invoice_line_credited_line');
        $this->addSql('ALTER TABLE invoice_line DROP credited_line_id');
    }
}
