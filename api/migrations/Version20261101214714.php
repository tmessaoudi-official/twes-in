<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261101214714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A numbering series keeps the day of its last number, which a day set back is refused against, and until when an operator lets it take an earlier one; both empty until then.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE numbering_series ADD last_numbered_day DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE numbering_series ADD earlier_day_allowed_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE numbering_series DROP earlier_day_allowed_until');
        $this->addSql('ALTER TABLE numbering_series DROP last_numbered_day');
    }
}
