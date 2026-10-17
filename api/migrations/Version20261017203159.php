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
 * The demo sandboxes the worker builds ahead, one ready for each country a visitor may pick: removing a sandbox's company
 * or its owner removes the sandbox.
 */
final class Version20261017203159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The demo sandboxes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE demo_sandbox (id UUID NOT NULL, country VARCHAR(2) NOT NULL, state VARCHAR(16) NOT NULL, queued_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, build_started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, ready_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, claimed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, company_id UUID DEFAULT NULL, owner_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_demo_sandbox_country_state ON demo_sandbox (country, state)');
        $this->addSql('CREATE INDEX IDX_2141A5A4979B1AD6 ON demo_sandbox (company_id)');
        $this->addSql('CREATE INDEX IDX_2141A5A47E3C61F9 ON demo_sandbox (owner_id)');
        $this->addSql('ALTER TABLE demo_sandbox ADD CONSTRAINT FK_2141A5A4979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE demo_sandbox ADD CONSTRAINT FK_2141A5A47E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE demo_sandbox');
    }
}
