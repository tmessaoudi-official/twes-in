<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The invitation table: an offer to join a company made to an address with no account yet (G1b). */
final class Version20260909214300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invitations: single-use, expiring offers to join a company, stored by token hash.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invitation (id UUID NOT NULL, email VARCHAR(254) NOT NULL, role_name VARCHAR(64) NOT NULL, token_hash VARCHAR(64) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, company_id UUID NOT NULL, invited_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invitation_company ON invitation (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invitation_token_hash ON invitation (token_hash)');
        $this->addSql('CREATE INDEX IDX_F11D61A2A7B4A7E3 ON invitation (invited_by_id)');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invitation ADD CONSTRAINT FK_F11D61A2A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A2979B1AD6');
        $this->addSql('ALTER TABLE invitation DROP CONSTRAINT FK_F11D61A2A7B4A7E3');
        $this->addSql('DROP TABLE invitation');
    }
}
