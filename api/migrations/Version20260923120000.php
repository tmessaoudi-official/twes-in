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
 * A phone lent to a computer tab as a scanner (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4). Only hashes of the
 * single-use link and of the phone's key are stored; the link hash is unique so a lookup finds one pairing at most.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phones paired to a computer tab as scanners.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE scan_pairing (id UUID NOT NULL, tab VARCHAR(64) NOT NULL, link_hash VARCHAR(64) NOT NULL, key_hash VARCHAR(64) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, alive_until TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, company_id UUID NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_scan_pairing_user ON scan_pairing (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_scan_pairing_link ON scan_pairing (link_hash)');
        $this->addSql('CREATE INDEX IDX_D2CF82C2979B1AD6 ON scan_pairing (company_id)');
        $this->addSql('ALTER TABLE scan_pairing ADD CONSTRAINT FK_D2CF82C2979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE scan_pairing ADD CONSTRAINT FK_D2CF82C2A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scan_pairing');
    }
}
