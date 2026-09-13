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
 * G3b company profile (docs/SPEC.md § 4 company): legal identity, the preset's registration numbers, address, contact,
 * banking, VAT regime and the texts printed on invoices. Every existing company starts with an empty profile under
 * the standard regime.
 */
final class Version20260913203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Company profile columns.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE company
                ADD legal_name VARCHAR(200) DEFAULT NULL,
                ADD legal_form VARCHAR(80) DEFAULT NULL,
                ADD identifiers JSONB DEFAULT '{}' NOT NULL,
                ADD address_line1 VARCHAR(200) DEFAULT NULL,
                ADD address_line2 VARCHAR(200) DEFAULT NULL,
                ADD postal_code VARCHAR(20) DEFAULT NULL,
                ADD city VARCHAR(120) DEFAULT NULL,
                ADD email VARCHAR(254) DEFAULT NULL,
                ADD phone VARCHAR(40) DEFAULT NULL,
                ADD website VARCHAR(255) DEFAULT NULL,
                ADD iban VARCHAR(34) DEFAULT NULL,
                ADD bic VARCHAR(11) DEFAULT NULL,
                ADD vat_regime VARCHAR(32) DEFAULT 'standard' NOT NULL,
                ADD invoice_footer_text TEXT DEFAULT NULL,
                ADD late_penalty_text TEXT DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE company
                DROP legal_name,
                DROP legal_form,
                DROP identifiers,
                DROP address_line1,
                DROP address_line2,
                DROP postal_code,
                DROP city,
                DROP email,
                DROP phone,
                DROP website,
                DROP iban,
                DROP bic,
                DROP vat_regime,
                DROP invoice_footer_text,
                DROP late_penalty_text
            SQL);
    }
}
