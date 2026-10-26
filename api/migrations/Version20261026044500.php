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
 * What the owner says is wrong when opening the company to support, so the operator knows where to look.
 */
final class Version20261026044500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Support access keeps what the owner says is wrong.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_grant ADD reason VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE support_grant DROP reason');
    }
}
