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
 * How each person wants each kind of notification told, per company: the bell and the e-mail. A personal kind has no
 * company, and PostgreSQL counts NULLs as distinct in a unique index, so the key is `NULLS NOT DISTINCT`: without it a
 * personal choice could be written twice.
 */
final class Version20261009010150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'How each person wants each kind of notification told';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE notification_preference (id UUID NOT NULL, type VARCHAR(80) NOT NULL, bell BOOLEAN NOT NULL, email BOOLEAN NOT NULL, user_id UUID NOT NULL, company_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_preference ON notification_preference (user_id, company_id, type) NULLS NOT DISTINCT');
        $this->addSql('CREATE INDEX IDX_A61B1571A76ED395 ON notification_preference (user_id)');
        $this->addSql('CREATE INDEX IDX_A61B1571979B1AD6 ON notification_preference (company_id)');
        $this->addSql('ALTER TABLE notification_preference ADD CONSTRAINT FK_A61B1571A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_preference ADD CONSTRAINT FK_A61B1571979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification_preference DROP CONSTRAINT FK_A61B1571A76ED395');
        $this->addSql('ALTER TABLE notification_preference DROP CONSTRAINT FK_A61B1571979B1AD6');
        $this->addSql('DROP TABLE notification_preference');
    }
}
