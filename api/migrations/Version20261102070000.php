<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102070000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Every account that exists keeps the calmer colour style it had: a new account starts vivid, and nobody\'s screen changes under them on the update.';
    }

    public function up(Schema $schema): void
    {
        // An account that already chose keeps its choice; the update's moment is when it happened, as a person's save is.
        $this->addSql(<<<'SQL'
            INSERT INTO setting (id, level, level_id, company_id, key, value, updated_at, version)
            SELECT gen_random_uuid(), 'user', u.id::text, NULL, 'presentation.theme-preset', '"calme"'::jsonb, now(), 1
            FROM "user" u
            ON CONFLICT (level, level_id, key) DO NOTHING
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Nothing is removed: the rows are every person's colour style, chosen after the update too, and the release
        // before reads only the settings it declares, so they do it no harm; a rollback runs that release on this schema.
    }
}
