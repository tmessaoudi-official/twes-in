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
 * Every stored language becomes a BCP 47 tag of up to 35 characters, the length RFC 5646 asks a store to keep, so a
 * regional variant or a script subtag fits. Widening is free while no release has shipped: once one has, the update
 * gate refuses a type change, and the same widening would take a new column and a release to drop the old one.
 */
final class Version20261018063001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Language columns hold a BCP 47 tag of up to 35 characters';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ALTER locale TYPE VARCHAR(35)');
        $this->addSql('ALTER TABLE company ALTER locale TYPE VARCHAR(35)');
        $this->addSql('ALTER TABLE signup ALTER locale TYPE VARCHAR(35)');
        $this->addSql('ALTER TABLE legal_text ALTER language TYPE VARCHAR(35)');
        $this->addSql('ALTER TABLE invoice ALTER language TYPE VARCHAR(35)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ALTER locale TYPE VARCHAR(5)');
        $this->addSql('ALTER TABLE company ALTER locale TYPE VARCHAR(5)');
        $this->addSql('ALTER TABLE signup ALTER locale TYPE VARCHAR(5)');
        $this->addSql('ALTER TABLE legal_text ALTER language TYPE VARCHAR(2)');
        $this->addSql('ALTER TABLE invoice ALTER language TYPE VARCHAR(8)');
    }
}
