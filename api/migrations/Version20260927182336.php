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
 * How an issued invoice or a validated delivery note printed (PrintSettings, DeliveryNotePrint). Documents issued
 * before this column existed are left empty and keep reading their settings live, as they did: those settings resolve
 * through the settings chain and the defaults the PHP declarations hold, which SQL here would only copy and let drift.
 */
final class Version20260927182336 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Print settings kept on invoices and delivery notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD print_settings JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_note ADD print_settings JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP print_settings');
        $this->addSql('ALTER TABLE delivery_note DROP print_settings');
    }
}
