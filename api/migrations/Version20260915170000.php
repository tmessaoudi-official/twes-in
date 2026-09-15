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
 * G1c passkeys (docs/SPEC.md § 4 passkey): a WebAuthn credential per row, owned by one user and gone with them. The
 * credential id is unique across every account, because an authenticator mints a fresh one for each registration.
 */
final class Version20260915170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Passkeys as a second factor.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE passkey (id UUID NOT NULL, user_id UUID NOT NULL, credential_id VARCHAR(255) NOT NULL, record TEXT NOT NULL, name VARCHAR(80) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_passkey_credential ON passkey (credential_id)');
        $this->addSql('CREATE INDEX idx_passkey_user ON passkey (user_id)');
        $this->addSql('ALTER TABLE passkey ADD CONSTRAINT fk_passkey_user FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE passkey');
    }
}
