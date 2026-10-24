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
 * The texts worded otherwise than shipped, the installation's (no company) and each company's. PostgreSQL counts NULLs
 * as distinct in a unique index, so the key is `NULLS NOT DISTINCT`: without it the installation could hold one text
 * twice.
 */
final class Version20261024185233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Texts worded otherwise than shipped, for the installation or for one company.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE text_override (id UUID NOT NULL, language VARCHAR(16) NOT NULL, domain VARCHAR(64) NOT NULL, text_key VARCHAR(255) NOT NULL, value TEXT NOT NULL, original TEXT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, version INT DEFAULT 1 NOT NULL, company_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_text_override ON text_override (company_id, language, domain, text_key) NULLS NOT DISTINCT');
        $this->addSql('CREATE INDEX IDX_34418AF7979B1AD6 ON text_override (company_id)');
        $this->addSql('ALTER TABLE text_override ADD CONSTRAINT FK_34418AF7979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE text_override DROP CONSTRAINT FK_34418AF7979B1AD6');
        $this->addSql('DROP TABLE text_override');
    }
}
