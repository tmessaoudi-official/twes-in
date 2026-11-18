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
 * A support grant is a record the company keeps: deleting the operator it names clears the name and keeps the row,
 * which now says on its own that it named someone, so it opens to no one rather than to every operator. Grants already
 * naming one are marked as such.
 */
final class Version20261118025051 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A support grant keeps that it named an operator when that account is deleted.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_grant ADD operator_named BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('UPDATE support_grant SET operator_named = true WHERE operator_id IS NOT NULL');
        $this->addSql('ALTER TABLE support_grant DROP CONSTRAINT FK_3BBF7094584598A3');
        $this->addSql('ALTER TABLE support_grant ADD CONSTRAINT FK_3BBF7094584598A3 FOREIGN KEY (operator_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE'); // contract: the release before does not know operator_id and writes it NULL, which the key never refuses
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_grant DROP CONSTRAINT FK_3BBF7094584598A3');
        $this->addSql('ALTER TABLE support_grant ADD CONSTRAINT FK_3BBF7094584598A3 FOREIGN KEY (operator_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE support_grant DROP operator_named');
    }
}
