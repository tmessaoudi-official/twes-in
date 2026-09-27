<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Who an expense paid, in words, when no vendor record names them. Never beside a vendor, which the check keeps. */
final class Version20260927204018 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An expense names its payee in words';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense ADD payee VARCHAR(160) DEFAULT NULL');
        $this->addSql('ALTER TABLE expense ADD CONSTRAINT chk_expense_payee_or_vendor CHECK (payee IS NULL OR vendor_id IS NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense DROP CONSTRAINT chk_expense_payee_or_vendor');
        $this->addSql('ALTER TABLE expense DROP payee');
    }
}
