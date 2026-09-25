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
 * A delivery note line names the lot or serial handed over (docs/SPEC.md § 7, 2026-09-24 12:40 row 5), which
 * validation takes out of stock instead of the first to expire. Nullable: most lines name none.
 */
final class Version20260925044647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The lot or serial a delivery note line hands over.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note_line ADD lot_code VARCHAR(40) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note_line DROP lot_code');
    }
}
