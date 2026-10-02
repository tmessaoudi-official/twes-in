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
 * Forgot password (docs/SPEC.md § 7, row 50): a mailed link that lets whoever reads the inbox choose a new password.
 * Only the token's SHA-256 is stored, so a leaked backup yields no usable link; a link goes with its account.
 */
final class Version20261002230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The password reset links.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE password_reset (id UUID NOT NULL, user_id UUID NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_password_reset_token_hash ON password_reset (token_hash)');
        $this->addSql('CREATE INDEX idx_password_reset_user ON password_reset (user_id)');
        $this->addSql('ALTER TABLE password_reset ADD CONSTRAINT FK_PASSWORD_RESET_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE password_reset');
    }
}
