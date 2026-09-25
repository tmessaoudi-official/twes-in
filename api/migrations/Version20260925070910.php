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
 * The company a sign-in opens (docs/SPEC.md § 7, 2026-09-25 09:03): when a person last worked in each of their
 * companies, and the one they pinned. Nothing is backfilled: a membership never used falls back to the name order.
 */
final class Version20260925070910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The company a sign-in opens: last used, or pinned.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE membership ADD last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE membership ADD opened_at_sign_in BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_membership_opened_at_sign_in ON membership (user_id) WHERE opened_at_sign_in');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_membership_opened_at_sign_in');
        $this->addSql('ALTER TABLE membership DROP last_used_at');
        $this->addSql('ALTER TABLE membership DROP opened_at_sign_in');
    }
}
