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
 * G3b settings engine: one value per level, subject and key (docs/SPEC.md § 3 Settings). `level_id` addresses the
 * subject (a company, a company and role, a user); `company_id` is kept for every level inside a company so its
 * settings go with it.
 */
final class Version20260913175702 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Settings engine: the setting table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE setting (id UUID NOT NULL, level VARCHAR(24) NOT NULL, level_id VARCHAR(80) NOT NULL, key VARCHAR(160) NOT NULL, value JSONB NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_setting_level_key ON setting (level, level_id, key)');
        $this->addSql('CREATE INDEX IDX_9F74B898979B1AD6 ON setting (company_id)');
        $this->addSql('ALTER TABLE setting ADD CONSTRAINT FK_9F74B898979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE setting DROP CONSTRAINT FK_9F74B898979B1AD6');
        $this->addSql('DROP TABLE setting');
    }
}
