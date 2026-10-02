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
 * Connected devices (docs/SPEC.md § 7, row 50): what a person sees of where they are signed in, and the means to end one.
 * Only the SHA-256 of the session id is stored; a session goes with its account.
 */
final class Version20261003090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The sessions a person is signed in on, as their security page lists them.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_session (id UUID NOT NULL, user_id UUID NOT NULL, session_hash VARCHAR(64) NOT NULL, device VARCHAR(255) NOT NULL, address VARCHAR(45) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_session_hash ON user_session (session_hash)');
        $this->addSql('CREATE INDEX idx_user_session_user ON user_session (user_id)');
        $this->addSql('ALTER TABLE user_session ADD CONSTRAINT FK_USER_SESSION_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_session');
    }
}
