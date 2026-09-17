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
 * The trigram index an expenses list is searched through (docs/SPEC.md § 7, lists at scale). Its expression is the one
 * DoctrineExpenseRepository::MATCHES_WORDS asks for, character for character: PostgreSQL uses the index only when the
 * query repeats it exactly, and nothing fails when the two drift apart — the search still answers, one scan at a time.
 */
final class Version20260918090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The trigram index expenses are searched with.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_expense_search ON expense USING gin (search_text(description, reference) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_expense_search');
    }
}
