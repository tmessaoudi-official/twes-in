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
 * Subscriptions (docs/SPEC.md § 7, 2026-09-17): one per company licensing manages. The checks repeat the domain's
 * refusals, so a row written around the application still cannot hold terms the standing cannot be computed from.
 */
final class Version20260917180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The subscription table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subscription (id UUID NOT NULL, period_count SMALLINT NOT NULL, period_unit VARCHAR(8) NOT NULL, trial_ends_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, paid_until TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL, price NUMERIC(13, 3) DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, grace_days SMALLINT DEFAULT NULL, unpaid_mode VARCHAR(16) DEFAULT NULL, created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_subscription_company ON subscription (company_id)');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D3979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql("ALTER TABLE subscription ADD CONSTRAINT chk_subscription_period CHECK (period_count BETWEEN 1 AND 1200 AND period_unit IN ('day', 'month', 'year'))");
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT chk_subscription_covered CHECK (trial_ends_at IS NOT NULL OR paid_until IS NOT NULL)');
        $this->addSql("ALTER TABLE subscription ADD CONSTRAINT chk_subscription_unpaid CHECK ((grace_days IS NULL OR grace_days BETWEEN 0 AND 365) AND (unpaid_mode IS NULL OR unpaid_mode IN ('read_only', 'locked')))");
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT chk_subscription_price CHECK ((price IS NULL) = (currency IS NULL) AND (price IS NULL OR price >= 0))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE subscription');
    }
}
