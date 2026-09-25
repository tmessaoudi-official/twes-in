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
 * An invoice line keeps what one unit of its product cost when it was issued (docs/SPEC.md § 7, 2026-09-24 11:40,
 * RPT-01). Nullable: a draft freezes nothing, and a line issued before this keeps none.
 */
final class Version20260925041906 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The cost an invoice line froze at issue.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line ADD unit_cost NUMERIC(14, 4) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_line DROP unit_cost');
    }
}
