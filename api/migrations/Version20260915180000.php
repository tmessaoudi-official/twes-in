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
 * G1d signup (docs/SPEC.md § 4 signup): a mailed link to open an account and a company. Only the token's SHA-256 is
 * stored, unique; the address is indexed because asking again replaces the open link for it.
 */
final class Version20260915180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Signup links.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE signup (id UUID NOT NULL, email VARCHAR(254) NOT NULL, locale VARCHAR(5) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_signup_token_hash ON signup (token_hash)');
        $this->addSql('CREATE INDEX idx_signup_email ON signup (email)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE signup');
    }
}
