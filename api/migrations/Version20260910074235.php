<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The second factor: TOTP state on the user, its recovery codes, and the per-company requirement (G1c). */
final class Version20260910074235 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'MFA: TOTP secret and replay guard on user, recovery_code table, company.mfa_required.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recovery_code (id UUID NOT NULL, code_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_recovery_code_user ON recovery_code (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_recovery_code_hash ON recovery_code (user_id, code_hash)');
        $this->addSql('ALTER TABLE recovery_code ADD CONSTRAINT FK_2C8D0584A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE "user" ADD totp_secret VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD totp_confirmed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD totp_last_timestep INT DEFAULT NULL');
        // DEFAULT FALSE is load-bearing: without it the ADD fails outright on a table that already has rows,
        // and every deployment past the seed has one.
        $this->addSql('ALTER TABLE company ADD mfa_required BOOLEAN DEFAULT FALSE NOT NULL');
        // ... and dropped again immediately: the default existed only to fill the rows that were already
        // there. New rows get their value from the entity, and the mapping carries no default, so leaving
        // one behind would put the schema permanently out of sync with it.
        $this->addSql('ALTER TABLE company ALTER mfa_required DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recovery_code DROP CONSTRAINT FK_2C8D0584A76ED395');
        $this->addSql('DROP TABLE recovery_code');
        $this->addSql('ALTER TABLE company DROP mfa_required');
        $this->addSql('ALTER TABLE "user" DROP totp_secret');
        $this->addSql('ALTER TABLE "user" DROP totp_confirmed_at');
        $this->addSql('ALTER TABLE "user" DROP totp_last_timestep');
    }
}
