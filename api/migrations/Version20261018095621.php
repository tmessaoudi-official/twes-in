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
 * A committed import keeps how a person paired the file's own headings with the subject's columns, so the next file
 * written the same way is read the same way. Added nullable: the imports already kept needed no pairing.
 */
final class Version20261018095621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An import run keeps the pairing of its file\'s headings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_run ADD pairing JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_run DROP pairing');
    }
}
