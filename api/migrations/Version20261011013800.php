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
 * A file taken off is marked, not deleted, so that « Annuler » puts it back where it was.
 */
final class Version20261011013800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'When an attachment was taken off';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE attachment ADD removed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM attachment WHERE removed_at IS NOT NULL');
        $this->addSql('ALTER TABLE attachment DROP removed_at');
    }
}
