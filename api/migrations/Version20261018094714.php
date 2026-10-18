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
 * An account's language follows its person's own choice of screen language from this release on; a choice made before
 * is copied to the account once. The languages are the ones the screens spoke at this release, written here rather
 * than read from the catalogue so the step stays what it was whatever the catalogue becomes.
 *
 * Nothing records an account's language before, so going down leaves it as it is.
 */
final class Version20261018094714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'An account takes the screen language its person chose before.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE "user" u SET locale = s.value #>> '{}'
            FROM setting s
            WHERE s.level = 'user'
              AND s.key = 'presentation.language'
              AND s.level_id = u.id::text
              AND s.value #>> '{}' IN ('fr', 'en')
            SQL);
    }

    public function down(Schema $schema): void
    {
    }
}
