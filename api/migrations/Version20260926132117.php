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
 * « Me prévenir » (docs/SPEC.md § 7, 2026-09-26 10:08, row 150): the planned modules a company asked to be told about,
 * one row per company and module, `announced_at` set once its members are told the module arrived.
 */
final class Version20260926132117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The planned modules each company waits for.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE module_interest (id UUID NOT NULL, module_key VARCHAR(40) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, announced_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_module_interest_company ON module_interest (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_module_interest_company_key ON module_interest (company_id, module_key)');
        $this->addSql('ALTER TABLE module_interest ADD CONSTRAINT FK_8FEC999979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE module_interest DROP CONSTRAINT FK_8FEC999979B1AD6');
        $this->addSql('DROP TABLE module_interest');
    }
}
