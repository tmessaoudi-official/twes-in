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
 * Whether a delivery note's goods were handed over, beside its delivery moment: a note invoiced straight from validated
 * keeps the day it expected, which is not a delivery. A note already delivered says so by its status or, once invoiced,
 * by the `delivery_note.delivered` entry its delivery recorded.
 */
final class Version20261016050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Whether a delivery note was delivered';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note ADD delivered BOOLEAN DEFAULT false NOT NULL');
        $this->addSql(<<<'SQL'
            UPDATE delivery_note n
               SET delivered = true
             WHERE n.status = 'delivered'
                OR EXISTS (SELECT 1 FROM audit_log a WHERE a.entity_id = n.id AND a.action = 'delivery_note.delivered')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE delivery_note DROP delivered');
    }
}
