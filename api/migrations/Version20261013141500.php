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
 * A person can ask for their account to be deleted; until the nightly run deletes it, signing in again takes it back.
 * The nightly run reads only the accounts that asked, so they have an index of their own.
 */
final class Version20261013141500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'When a person asked for their account to be deleted';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD deletion_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_user_deletion_requested ON "user" (deletion_requested_at) WHERE (deletion_requested_at IS NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_user_deletion_requested');
        $this->addSql('ALTER TABLE "user" DROP deletion_requested_at');
    }
}
