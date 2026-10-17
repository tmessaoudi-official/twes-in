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
 * The version Doctrine counts on a custom field, an establishment and a numbering series, so a save of an older
 * version than the one stored is refused rather than overwriting what someone else saved in between: for a series,
 * a number given since would otherwise be written back over. Every row starts at 1.
 */
final class Version20261017050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The version of a custom field, an establishment and a numbering series';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_field_definition ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE establishment ADD version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE numbering_series ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE numbering_series DROP version');
        $this->addSql('ALTER TABLE establishment DROP version');
        $this->addSql('ALTER TABLE custom_field_definition DROP version');
    }
}
