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
 * The owner may open the company to support for one operator by name. Every grant already there names no one and keeps
 * opening to every operator; deleting the named account deletes its grant, since clearing the name would widen it.
 */
final class Version20261118012539 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support access may name the one operator it opens to.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_grant ADD operator_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE support_grant ADD CONSTRAINT FK_3BBF7094584598A3 FOREIGN KEY (operator_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_3BBF7094584598A3 ON support_grant (operator_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_grant DROP CONSTRAINT FK_3BBF7094584598A3');
        $this->addSql('DROP INDEX IDX_3BBF7094584598A3');
        $this->addSql('ALTER TABLE support_grant DROP operator_id');
    }
}
