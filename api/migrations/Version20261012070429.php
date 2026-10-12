<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** « Accès support »: the windows an owner opens the company to the platform's operators. */
final class Version20261012070429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support access grants';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE support_grant (id UUID NOT NULL, starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, company_id UUID NOT NULL, granted_by_id UUID DEFAULT NULL, ended_by_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_support_grant_company_ends ON support_grant (company_id, ends_at)');
        $this->addSql('CREATE INDEX IDX_3BBF7094979B1AD6 ON support_grant (company_id)');
        $this->addSql('CREATE INDEX IDX_3BBF70943151C11F ON support_grant (granted_by_id)');
        $this->addSql('CREATE INDEX IDX_3BBF7094F24AC4EF ON support_grant (ended_by_id)');
        $this->addSql('ALTER TABLE support_grant ADD CONSTRAINT FK_3BBF7094979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE support_grant ADD CONSTRAINT FK_3BBF70943151C11F FOREIGN KEY (granted_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE support_grant ADD CONSTRAINT FK_3BBF7094F24AC4EF FOREIGN KEY (ended_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_grant DROP CONSTRAINT FK_3BBF7094979B1AD6');
        $this->addSql('ALTER TABLE support_grant DROP CONSTRAINT FK_3BBF70943151C11F');
        $this->addSql('ALTER TABLE support_grant DROP CONSTRAINT FK_3BBF7094F24AC4EF');
        $this->addSql('DROP TABLE support_grant');
    }
}
