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
 * An invoice line names the lot or serial sold (docs/SPEC.md § 7, 2026-09-24 12:40 row 5), a record only: an invoice
 * moves no stock. Nullable: most lines name none.
 */
final class Version20260925053500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The lot or serial an invoice line sells.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line ADD lot_code VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line DROP lot_code');
    }
}
