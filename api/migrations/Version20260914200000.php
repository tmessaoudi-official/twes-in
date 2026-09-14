<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** What a delivery note's customer was called the day the note was validated (docs/SPEC.md § 4 delivery_note). */
final class Version20260914200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delivery note customer snapshot';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note ADD customer_snapshot JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note DROP customer_snapshot');
    }
}
