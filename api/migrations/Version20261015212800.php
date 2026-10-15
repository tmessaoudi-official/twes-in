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
 * Recurring invoices move onto the schedule recurring expenses use. Each frequency is written as the schedule that
 * gives the same days: weekly a week, monthly and quarterly one and three months, yearly a year, each month or year on
 * its first day's date, which a shorter month clamps as before. The count and the next day stay as they were. Going
 * back refuses, before changing anything, a schedule none of the four frequencies says.
 */
final class Version20261015212800 extends AbstractMigration
{
    /** A schedule one of the four frequencies says: a week, or one or three months or a year on the first day's date. */
    private const string SAID_BY_A_FREQUENCY = "(schedule_unit = 'week' AND schedule_every = 1)"
        ." OR (schedule_unit = 'month' AND schedule_every IN (1, 3) AND schedule_anchor = 'date' AND schedule_month_day = EXTRACT(DAY FROM starts_on))"
        ." OR (schedule_unit = 'year' AND schedule_every = 1 AND schedule_anchor = 'date' AND schedule_month_day = EXTRACT(DAY FROM starts_on))";

    public function getDescription(): string
    {
        return 'Recurring invoices on the shared schedule';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE recurring_invoice ADD schedule_unit VARCHAR(8) DEFAULT NULL, ADD schedule_every SMALLINT DEFAULT NULL, ADD schedule_anchor VARCHAR(16) DEFAULT NULL, ADD schedule_month_day SMALLINT DEFAULT NULL, ADD schedule_ordinal SMALLINT DEFAULT NULL, ADD schedule_weekday SMALLINT DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE recurring_invoice SET
                schedule_unit = CASE frequency WHEN 'weekly' THEN 'week' WHEN 'yearly' THEN 'year' ELSE 'month' END,
                schedule_every = CASE frequency WHEN 'quarterly' THEN 3 ELSE 1 END,
                schedule_anchor = CASE frequency WHEN 'weekly' THEN NULL ELSE 'date' END,
                schedule_month_day = CASE frequency WHEN 'weekly' THEN NULL ELSE EXTRACT(DAY FROM starts_on) END
            SQL);
        $this->addSql('ALTER TABLE recurring_invoice ALTER schedule_unit SET NOT NULL, ALTER schedule_every SET NOT NULL');
        $this->addSql('ALTER TABLE recurring_invoice DROP CONSTRAINT ck_recurring_invoice_frequency');
        $this->addSql('ALTER TABLE recurring_invoice DROP frequency');
        $this->addSql("ALTER TABLE recurring_invoice ADD CONSTRAINT ck_recurring_invoice_schedule CHECK (schedule_unit IN ('day', 'week', 'month', 'year') AND schedule_every BETWEEN 1 AND 99 AND (schedule_anchor IS NULL OR schedule_anchor IN ('date', 'last_day', 'weekday', 'last_weekday')) AND (schedule_anchor IS NULL) = (schedule_unit IN ('day', 'week')) AND (schedule_month_day IS NULL OR schedule_month_day BETWEEN 1 AND 31) AND (schedule_ordinal IS NULL OR schedule_ordinal BETWEEN 1 AND 4) AND (schedule_weekday IS NULL OR schedule_weekday BETWEEN 1 AND 7))");
    }

    public function down(Schema $schema): void
    {
        $unsaid = $this->connection->fetchOne('SELECT count(*) FROM recurring_invoice WHERE NOT ('.self::SAID_BY_A_FREQUENCY.')');
        $this->abortIf(0 !== (int) $unsaid, \sprintf('%d recurring invoices recur on a schedule weekly, monthly, quarterly and yearly cannot say; change or delete them first.', (int) $unsaid));

        $this->addSql('ALTER TABLE recurring_invoice ADD frequency VARCHAR(16) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE recurring_invoice SET frequency = CASE
                WHEN schedule_unit = 'week' THEN 'weekly'
                WHEN schedule_unit = 'year' THEN 'yearly'
                WHEN schedule_every = 3 THEN 'quarterly'
                ELSE 'monthly' END
            SQL);
        $this->addSql('ALTER TABLE recurring_invoice ALTER frequency SET NOT NULL');
        $this->addSql('ALTER TABLE recurring_invoice DROP CONSTRAINT ck_recurring_invoice_schedule');
        $this->addSql('ALTER TABLE recurring_invoice DROP schedule_unit, DROP schedule_every, DROP schedule_anchor, DROP schedule_month_day, DROP schedule_ordinal, DROP schedule_weekday');
        $this->addSql("ALTER TABLE recurring_invoice ADD CONSTRAINT ck_recurring_invoice_frequency CHECK (frequency IN ('weekly', 'monthly', 'quarterly', 'yearly'))");
    }
}
