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
 * A credit note states why it corrects its invoice (docs/SPEC.md § 7, 2026-09-24 22:51). Nullable: an invoice has no
 * reason, and a credit note drafted before this was asked keeps none.
 */
final class Version20260925032849 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The reason a credit note states.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice ADD credit_note_reason VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP credit_note_reason');
    }
}
