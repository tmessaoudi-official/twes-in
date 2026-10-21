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
 * « À vérifier » on a vendor and a customer: the mark a person sets with its note, and of a vendor a name a file could
 * not vouch for. A customer's missing number is read from its identifiers as they are, never stored.
 */
final class Version20261021084700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A vendor carries « À vérifier », its note and a name in doubt; a customer « À vérifier » and its note.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendor ADD to_check BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE vendor ADD check_note VARCHAR(500) DEFAULT NULL');
        $this->addSql('ALTER TABLE vendor ADD name_doubtful BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE customer ADD to_check BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE customer ADD check_note VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendor DROP to_check');
        $this->addSql('ALTER TABLE vendor DROP check_note');
        $this->addSql('ALTER TABLE vendor DROP name_doubtful');
        $this->addSql('ALTER TABLE customer DROP to_check');
        $this->addSql('ALTER TABLE customer DROP check_note');
    }
}
