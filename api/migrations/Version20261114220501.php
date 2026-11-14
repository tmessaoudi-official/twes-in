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
 * Each passkey keeps the name it was made on, the only one it works on, so a move of the installation's passkeys to the
 * shop's Tailscale name (or back) knows whose keys stopped working. Every key made before has none, read as the
 * installation's own; the release before this one reads no such column.
 */
final class Version20261114220501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The name each passkey was made on.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE passkey ADD host VARCHAR(253) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE passkey DROP host');
    }
}
