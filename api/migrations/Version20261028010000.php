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
 * A company keeps when it was first opened, so a suspension of one never opened is told as a refusal, and the reason
 * an operator gave its refusal or suspension, which its owners' mail and sign-in page say. The companies of before
 * learn when they were opened in the next migration.
 */
final class Version20261028010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A company keeps when it was first opened and the reason of its suspension.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE company ADD suspension_reason VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP suspension_reason');
        $this->addSql('ALTER TABLE company DROP approved_at');
    }
}
