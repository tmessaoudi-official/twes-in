<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** The notification centre: one inbox_item row per recipient of a published notification (G2a). */
final class Version20260913115212 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification centre: inbox_item table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE inbox_item (id UUID NOT NULL, type VARCHAR(80) NOT NULL, payload JSON NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, recipient_id UUID NOT NULL, company_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_inbox_item_recipient_created ON inbox_item (recipient_id, created_at)');
        $this->addSql('CREATE INDEX IDX_D684AB84E92F8F78 ON inbox_item (recipient_id)');
        $this->addSql('CREATE INDEX IDX_D684AB84979B1AD6 ON inbox_item (company_id)');
        $this->addSql('ALTER TABLE inbox_item ADD CONSTRAINT FK_D684AB84E92F8F78 FOREIGN KEY (recipient_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE inbox_item ADD CONSTRAINT FK_D684AB84979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inbox_item DROP CONSTRAINT FK_D684AB84E92F8F78');
        $this->addSql('ALTER TABLE inbox_item DROP CONSTRAINT FK_D684AB84979B1AD6');
        $this->addSql('DROP TABLE inbox_item');
    }
}
