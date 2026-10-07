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
 * When an invitation's mail failed for good, so « À surveiller » can show it to whoever may invite until it is mailed
 * (docs/SPEC.md § 7, Messenger transport, row 56).
 */
final class Version20261007023954 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An invitation remembers when its mail failed for good.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation ADD mail_failed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invitation DROP mail_failed_at');
    }
}
