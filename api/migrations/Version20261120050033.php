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
 * A delete may empty a link that only sets something up (records.configuration_links): its copy names the column it
 * emptied, holding only the row's id and the value, for the restore to write back. Every copy made before holds a whole
 * row and names none.
 */
final class Version20261120050033 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A copied row may be a link the delete emptied, naming its column.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE record_removal_row ADD cleared_column VARCHAR(63) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE record_removal_row DROP cleared_column');
    }
}
