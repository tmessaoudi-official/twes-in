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
 * An expense takes its supplier's bill as printed: lines each a net and the rates on it, and the bill's taxes with their
 * base, worked-out and charged amounts, the stamp among them. Every expense written before becomes one line carrying
 * the one rate it had, which is what it was.
 */
final class Version20261014193500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Expense lines and taxes, so an expense carries several rates and the stamp.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE expense_line (
              id UUID NOT NULL,
              position INT NOT NULL,
              amount_net NUMERIC(14, 3) NOT NULL,
              expense_id UUID NOT NULL,
              company_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_expense_line_expense ON expense_line (expense_id)');
        $this->addSql('CREATE INDEX idx_expense_line_company ON expense_line (company_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE expense_line_tax (
              id UUID NOT NULL,
              position INT NOT NULL,
              code VARCHAR(32) NOT NULL,
              rate NUMERIC(6, 3) NOT NULL,
              enters_vat_base BOOLEAN NOT NULL,
              line_id UUID NOT NULL,
              company_id UUID NOT NULL,
              tax_component_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_expense_line_tax_line ON expense_line_tax (line_id)');
        $this->addSql('CREATE INDEX idx_expense_line_tax_company ON expense_line_tax (company_id)');
        $this->addSql('CREATE INDEX idx_expense_line_tax_component ON expense_line_tax (tax_component_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE expense_tax (
              id UUID NOT NULL,
              position INT NOT NULL,
              code VARCHAR(32) NOT NULL,
              kind VARCHAR(32) NOT NULL,
              rate NUMERIC(6, 3) DEFAULT NULL,
              enters_vat_base BOOLEAN NOT NULL,
              base NUMERIC(14, 3) DEFAULT NULL,
              worked_out_amount NUMERIC(14, 3) NOT NULL,
              amount NUMERIC(14, 3) NOT NULL,
              expense_id UUID NOT NULL,
              company_id UUID NOT NULL,
              tax_component_id UUID NOT NULL,
              PRIMARY KEY (id)
            )
        SQL);
        $this->addSql('CREATE INDEX idx_expense_tax_expense ON expense_tax (expense_id)');
        $this->addSql('CREATE INDEX idx_expense_tax_company ON expense_tax (company_id)');
        $this->addSql('CREATE INDEX idx_expense_tax_tax_component ON expense_tax (tax_component_id)');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              expense_line
            ADD
              CONSTRAINT FK_65B3FA94F395DB7B FOREIGN KEY (expense_id) REFERENCES expense (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              expense_line
            ADD
              CONSTRAINT FK_65B3FA94979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              expense_line_tax
            ADD
              CONSTRAINT FK_BBAB4BD54D7B7542 FOREIGN KEY (line_id) REFERENCES expense_line (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              expense_line_tax
            ADD
              CONSTRAINT FK_BBAB4BD5979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              expense_line_tax
            ADD
              CONSTRAINT FK_BBAB4BD5729B0C67 FOREIGN KEY (tax_component_id) REFERENCES tax_component (id) NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              expense_tax
            ADD
              CONSTRAINT FK_2271EF8AF395DB7B FOREIGN KEY (expense_id) REFERENCES expense (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              expense_tax
            ADD
              CONSTRAINT FK_2271EF8A979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              expense_tax
            ADD
              CONSTRAINT FK_2271EF8A729B0C67 FOREIGN KEY (tax_component_id) REFERENCES tax_component (id) NOT DEFERRABLE
        SQL);
        $this->addSql('ALTER TABLE expense ADD fixed_amount NUMERIC(14, 3) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE expense ALTER fixed_amount DROP DEFAULT');

        $this->addSql('INSERT INTO expense_line (id, position, amount_net, expense_id, company_id) SELECT gen_random_uuid(), 0, e.amount_net, e.id, e.company_id FROM expense e');
        $this->addSql(<<<'SQL'
            INSERT INTO expense_line_tax (id, position, code, rate, enters_vat_base, line_id, company_id, tax_component_id)
            SELECT gen_random_uuid(), 0, t.code, e.tax_rate, t.enters_vat_base, l.id, e.company_id, t.id
            FROM expense e JOIN expense_line l ON l.expense_id = e.id JOIN tax_component t ON t.id = e.tax_component_id
        SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO expense_tax (id, position, code, kind, rate, enters_vat_base, base, worked_out_amount, amount, expense_id, company_id, tax_component_id)
            SELECT gen_random_uuid(), 0, t.code, t.kind, e.tax_rate, t.enters_vat_base, e.amount_net, e.tax_amount, e.tax_amount, e.id, e.company_id, t.id
            FROM expense e JOIN tax_component t ON t.id = e.tax_component_id
        SQL);

        $this->addSql('ALTER TABLE expense DROP CONSTRAINT fk_expense_tax_component');
        $this->addSql('DROP INDEX idx_expense_tax_component');
        $this->addSql('ALTER TABLE expense DROP tax_component_id');
        $this->addSql('ALTER TABLE expense DROP tax_rate');
    }

    /** A bill of several rates comes back as its first rate with every rate's tax in its amount, and loses its stamp. */
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE expense ADD tax_component_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE expense ADD tax_rate NUMERIC(6, 3) DEFAULT NULL');
        $this->addSql(<<<'SQL'
            UPDATE expense e SET tax_component_id = first.tax_component_id, tax_rate = first.rate
            FROM (SELECT DISTINCT ON (expense_id) expense_id, tax_component_id, rate FROM expense_tax WHERE kind = 'percentage_line' ORDER BY expense_id, position) first
            WHERE first.expense_id = e.id
        SQL);
        $this->addSql('UPDATE expense SET amount_gross = amount_net + tax_amount');
        $this->addSql('ALTER TABLE expense_line DROP CONSTRAINT FK_65B3FA94F395DB7B');
        $this->addSql('ALTER TABLE expense_line DROP CONSTRAINT FK_65B3FA94979B1AD6');
        $this->addSql('ALTER TABLE expense_line_tax DROP CONSTRAINT FK_BBAB4BD54D7B7542');
        $this->addSql('ALTER TABLE expense_line_tax DROP CONSTRAINT FK_BBAB4BD5979B1AD6');
        $this->addSql('ALTER TABLE expense_line_tax DROP CONSTRAINT FK_BBAB4BD5729B0C67');
        $this->addSql('ALTER TABLE expense_tax DROP CONSTRAINT FK_2271EF8AF395DB7B');
        $this->addSql('ALTER TABLE expense_tax DROP CONSTRAINT FK_2271EF8A979B1AD6');
        $this->addSql('ALTER TABLE expense_tax DROP CONSTRAINT FK_2271EF8A729B0C67');
        $this->addSql('DROP TABLE expense_line');
        $this->addSql('DROP TABLE expense_line_tax');
        $this->addSql('DROP TABLE expense_tax');
        $this->addSql('ALTER TABLE expense DROP fixed_amount');
        $this->addSql(<<<'SQL'
            ALTER TABLE
              expense
            ADD
              CONSTRAINT fk_expense_tax_component FOREIGN KEY (tax_component_id) REFERENCES tax_component (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql('CREATE INDEX idx_expense_tax_component ON expense (tax_component_id)');
    }
}
