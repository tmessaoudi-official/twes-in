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
 * The version Doctrine counts on a company's subscription, so terms an operator read before another save, a payment's
 * confirmation included, are refused rather than written over it. Every row starts at 1.
 */
final class Version20261017150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The version of a company\'s subscription';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP version');
    }
}
