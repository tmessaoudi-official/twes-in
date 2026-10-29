<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A passkey keeps whether the browser made it to live on the device, so it can sign in alone.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE passkey ADD discoverable BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE passkey DROP discoverable');
    }
}
