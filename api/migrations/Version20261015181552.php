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
 * The recurring expenses: each names the expense its drafts copy, its schedule (every so many days, weeks, months or
 * years, a month or a year on the day it is anchored to), from which day and up to which, the next occurrence by its
 * count and its day, and how many drafts it made. The day of the next occurrence is indexed with the company, which is
 * how the worker finds what is due. An expense gains the flag of a draft whose amount is to be checked, indexed for
 * « À surveiller » on the flagged rows alone.
 */
final class Version20261015181552 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The recurring expenses, and the amount to check on a draft';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recurring_expense (id UUID NOT NULL, model_expense_id UUID NOT NULL, variable_amount BOOLEAN NOT NULL, last_expense_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, starts_on DATE NOT NULL, ends_on DATE DEFAULT NULL, next_index INT NOT NULL, next_on DATE DEFAULT NULL, drafted INT NOT NULL, paused BOOLEAN NOT NULL, schedule_unit VARCHAR(8) NOT NULL, schedule_every SMALLINT NOT NULL, schedule_anchor VARCHAR(16) DEFAULT NULL, schedule_month_day SMALLINT DEFAULT NULL, schedule_ordinal SMALLINT DEFAULT NULL, schedule_weekday SMALLINT DEFAULT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_recurring_expense_company_next ON recurring_expense (company_id, next_on)');
        $this->addSql('CREATE INDEX IDX_F5CC182F979B1AD6 ON recurring_expense (company_id)');
        $this->addSql('ALTER TABLE recurring_expense ADD CONSTRAINT FK_F5CC182F979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE recurring_expense ADD CONSTRAINT ck_recurring_expense_schedule CHECK (schedule_unit IN ('day', 'week', 'month', 'year') AND schedule_every BETWEEN 1 AND 99 AND (schedule_anchor IS NULL OR schedule_anchor IN ('date', 'last_day', 'weekday', 'last_weekday')) AND (schedule_anchor IS NULL) = (schedule_unit IN ('day', 'week')) AND (schedule_month_day IS NULL OR schedule_month_day BETWEEN 1 AND 31) AND (schedule_ordinal IS NULL OR schedule_ordinal BETWEEN 1 AND 4) AND (schedule_weekday IS NULL OR schedule_weekday BETWEEN 1 AND 7))");
        $this->addSql('ALTER TABLE recurring_expense ADD CONSTRAINT ck_recurring_expense_counts CHECK (next_index >= 0 AND drafted >= 0 AND (ends_on IS NULL OR ends_on >= starts_on))');
        $this->addSql('ALTER TABLE expense ADD amount_to_check BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('CREATE INDEX idx_expense_amount_to_check ON expense (company_id, expense_date) WHERE amount_to_check');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_expense DROP CONSTRAINT FK_F5CC182F979B1AD6');
        $this->addSql('DROP TABLE recurring_expense');
        $this->addSql('DROP INDEX idx_expense_amount_to_check');
        $this->addSql('ALTER TABLE expense DROP amount_to_check');
    }
}
