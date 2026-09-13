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
 * G3a fiscal setup: each company's tax components and units, the customer tax regimes of every preset, and the
 * preset a company's taxes come from. Existing companies take their country's preset; the seed then copies their
 * taxes and units, because a migration does not read the preset files.
 */
final class Version20260913164031 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fiscal setup: tax_component, unit and customer_tax_regime tables, company.fiscal_preset.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_tax_regime (id UUID NOT NULL, fiscal_preset VARCHAR(8) NOT NULL, code VARCHAR(24) NOT NULL, label_key VARCHAR(120) NOT NULL, excluded_families JSONB NOT NULL, mention_key VARCHAR(120) DEFAULT NULL, sort_order INT NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_customer_tax_regime_preset_code ON customer_tax_regime (fiscal_preset, code)');
        $this->addSql('CREATE TABLE tax_component (id UUID NOT NULL, code VARCHAR(32) NOT NULL, name VARCHAR(120) NOT NULL, kind VARCHAR(24) NOT NULL, family VARCHAR(16) NOT NULL, rate NUMERIC(6, 3) DEFAULT NULL, amount NUMERIC(14, 3) DEFAULT NULL, threshold NUMERIC(14, 3) DEFAULT NULL, enters_vat_base BOOLEAN NOT NULL, is_default BOOLEAN NOT NULL, is_active BOOLEAN NOT NULL, exemption_mention TEXT DEFAULT NULL, sort_order INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_tax_component_company_code ON tax_component (company_id, code)');
        $this->addSql('CREATE INDEX IDX_65C7AB7D979B1AD6 ON tax_component (company_id)');
        $this->addSql('CREATE TABLE unit (id UUID NOT NULL, code VARCHAR(3) NOT NULL, name VARCHAR(60) NOT NULL, decimals SMALLINT NOT NULL, is_active BOOLEAN NOT NULL, sort_order INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_unit_company_code ON unit (company_id, code)');
        $this->addSql('CREATE INDEX IDX_DCBB0C53979B1AD6 ON unit (company_id)');
        $this->addSql('ALTER TABLE tax_component ADD CONSTRAINT FK_65C7AB7D979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE unit ADD CONSTRAINT FK_DCBB0C53979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        // Added nullable, filled from the country, then required: a NOT NULL column cannot be added to existing rows.
        $this->addSql('ALTER TABLE company ADD fiscal_preset VARCHAR(8) DEFAULT NULL');
        $this->addSql('UPDATE company SET fiscal_preset = country_code');
        $this->addSql('ALTER TABLE company ALTER fiscal_preset SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tax_component DROP CONSTRAINT FK_65C7AB7D979B1AD6');
        $this->addSql('ALTER TABLE unit DROP CONSTRAINT FK_DCBB0C53979B1AD6');
        $this->addSql('DROP TABLE customer_tax_regime');
        $this->addSql('DROP TABLE tax_component');
        $this->addSql('DROP TABLE unit');
        $this->addSql('ALTER TABLE company DROP fiscal_preset');
    }
}
