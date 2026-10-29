<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029031900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An invitation remembers when its sender was told it ran out unused.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation ADD run_out_told_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // What ran out before senders were told of it stays untold: the first run would otherwise tell of every invitation
        // that ever lapsed, some of them long since sent again.
        $this->addSql('UPDATE invitation SET run_out_told_at = expires_at WHERE accepted_at IS NULL AND expires_at < NOW()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation DROP run_out_told_at');
    }
}
