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
 * A new authenticator app's secret waiting beside the one in force until a code from it confirms the replacement, so an
 * account moving to another phone never holds no factor at all.
 */
final class Version20261024202600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A replacement authenticator secret waiting beside the one in force.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD totp_replacement_secret VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP totp_replacement_secret');
    }
}
