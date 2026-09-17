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
 * Declared payments (docs/SPEC.md § 7, 2026-09-17): what a company says it paid and what the operator answered, kept
 * whole as the ledger of both. The partial unique index is the rule "one declaration waits at a time", held by the
 * database rather than by a check the application could race against; the other checks repeat the domain's refusals.
 */
final class Version20260917190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The payment_declaration table, and a subscription\'s own hold days.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment_declaration (id UUID NOT NULL, amount NUMERIC(13, 3) NOT NULL, currency VARCHAR(3) NOT NULL, method VARCHAR(16) NOT NULL, paid_on DATE NOT NULL, reference VARCHAR(64) DEFAULT NULL, note TEXT DEFAULT NULL, status VARCHAR(16) NOT NULL, declared_by UUID NOT NULL, declared_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, decided_by UUID DEFAULT NULL, decided_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, decision_note TEXT DEFAULT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_payment_declaration_company ON payment_declaration (company_id, declared_at)');
        $this->addSql('CREATE INDEX idx_payment_declaration_status ON payment_declaration (status, declared_at)');
        $this->addSql('CREATE INDEX IDX_8C2F0A18979B1AD6 ON payment_declaration (company_id)');
        $this->addSql('ALTER TABLE payment_declaration ADD CONSTRAINT FK_8C2F0A18979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE subscription ADD hold_days SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE payment_declaration ADD CONSTRAINT chk_payment_declaration_amount CHECK (amount > 0)');
        $this->addSql("ALTER TABLE payment_declaration ADD CONSTRAINT chk_payment_declaration_method CHECK (method IN ('cash', 'transfer', 'cheque', 'other'))");
        $this->addSql("ALTER TABLE payment_declaration ADD CONSTRAINT chk_payment_declaration_status CHECK (status IN ('declared', 'confirmed', 'rejected') AND ((status = 'declared') = (decided_at IS NULL)))");
        $this->addSql("CREATE UNIQUE INDEX uniq_payment_declaration_open ON payment_declaration (company_id) WHERE status = 'declared'");
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT chk_subscription_hold CHECK (hold_days IS NULL OR hold_days BETWEEN 0 AND 365)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment_declaration');
        $this->addSql('ALTER TABLE subscription DROP CONSTRAINT chk_subscription_hold');
        $this->addSql('ALTER TABLE subscription DROP hold_days');
    }
}
