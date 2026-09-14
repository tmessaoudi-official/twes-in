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
 * G5 module registry: the modules a company has switched, one row per company and module. A module without a row
 * is on; a row with no enabled_at is off.
 */
final class Version20260914140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The modules each company has switched on or off.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE module_state (id UUID NOT NULL, company_id UUID NOT NULL, module_key VARCHAR(40) NOT NULL, enabled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_module_state_company ON module_state (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_module_state_company_key ON module_state (company_id, module_key)');
        $this->addSql('ALTER TABLE module_state ADD CONSTRAINT fk_module_state_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE module_state');
    }
}
