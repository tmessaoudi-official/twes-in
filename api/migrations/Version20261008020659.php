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
 * A reminder stage names the draft of the late fee it charged, if the company charges one: the screen shows it beside
 * the stage, and a fee's own lateness is never charged again.
 */
final class Version20261008020659 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A reminder stage names the late fee it drafted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_reminder ADD late_fee_invoice_id UUID DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_invoice_reminder_late_fee ON invoice_reminder (late_fee_invoice_id) WHERE (late_fee_invoice_id IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_invoice_reminder_late_fee');
        $this->addSql('ALTER TABLE invoice_reminder DROP late_fee_invoice_id');
    }
}
