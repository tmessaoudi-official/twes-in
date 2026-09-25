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
 * What the company withheld from a supplier when paying an expense (docs/SPEC.md § 7, 2026-09-24 11:40, RPT-09).
 * Nullable: most payments withhold nothing, and an expense paid before this recorded none.
 */
final class Version20260925065500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The withholding an expense payment made.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense ADD withholding_rate NUMERIC(6, 3) DEFAULT NULL');
        $this->addSql('ALTER TABLE expense ADD withholding_amount NUMERIC(14, 3) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense DROP withholding_rate');
        $this->addSql('ALTER TABLE expense DROP withholding_amount');
    }
}
