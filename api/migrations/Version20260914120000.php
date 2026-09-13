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
 * G4 custom fields: the fields a company adds to one kind of record, a key declared once per kind in a company, and
 * the values a customer holds for them as one JSONB object keyed by field key.
 */
final class Version20260914120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Custom field definitions and the custom field values of customers.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE custom_field_definition (id UUID NOT NULL, company_id UUID NOT NULL, entity VARCHAR(16) NOT NULL, field_key VARCHAR(40) NOT NULL, label VARCHAR(80) NOT NULL, type VARCHAR(16) NOT NULL, required BOOLEAN NOT NULL, choices JSONB DEFAULT \'[]\' NOT NULL, sort_order INT NOT NULL, is_active BOOLEAN NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_custom_field_definition_company ON custom_field_definition (company_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_custom_field_definition_key ON custom_field_definition (company_id, entity, field_key)');
        $this->addSql('ALTER TABLE custom_field_definition ADD CONSTRAINT fk_custom_field_definition_company FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('ALTER TABLE customer ADD custom_fields JSONB DEFAULT \'{}\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer DROP custom_fields');
        $this->addSql('DROP TABLE custom_field_definition');
    }
}
