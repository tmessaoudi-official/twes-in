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
 * The reminder stages late invoices reached: one row per invoice and stage, which the unique index makes the decision
 * between two runs at once. A stage counts from one, on an invoice at least a day late, as the entity holds.
 */
final class Version20261007193717 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The reminder stages late invoices reached';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice_reminder (id UUID NOT NULL, invoice_id UUID NOT NULL, stage SMALLINT NOT NULL, days_late SMALLINT NOT NULL, reached_on DATE NOT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_invoice_reminder_company ON invoice_reminder (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_invoice_reminder_stage ON invoice_reminder (invoice_id, stage)');
        $this->addSql('ALTER TABLE invoice_reminder ADD CONSTRAINT FK_5F1F1518979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE invoice_reminder ADD CONSTRAINT ck_invoice_reminder_stage CHECK (stage >= 1 AND days_late >= 1)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice_reminder DROP CONSTRAINT FK_5F1F1518979B1AD6');
        $this->addSql('DROP TABLE invoice_reminder');
    }
}
