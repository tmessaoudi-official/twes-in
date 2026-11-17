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
 * An import keeps until when it can be taken back, its company's window when it ran (records.undoable_for), as a delete
 * and a merge keep theirs: a company choosing another window afterwards moves no undo already offered. A run made before
 * keeps the column empty and reads the operator's window, as it always has, so nothing is rewritten.
 */
final class Version20261117141836 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'import_run.undoable_until: the window an import ran with';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_run ADD undoable_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_run DROP undoable_until');
    }
}
