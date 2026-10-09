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
 * What France's law adds to an invoice (docs/fiscal/FR.md § 4a): whether a company opted to pay VAT on the débits, and,
 * on an invoice, the category of its operations (chosen on a draft, stated once issued) and the option as it stood at
 * issue. Every invoice issued before them states neither, as nothing was asked then.
 */
final class Version20261009073612 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The débits option of a company, and an invoice\'s category of operations and débits option';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company ADD vat_on_debits BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE invoice ADD operation_category VARCHAR(16) DEFAULT NULL');
        $this->addSql('ALTER TABLE invoice ADD vat_on_debits BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE company DROP vat_on_debits');
        $this->addSql('ALTER TABLE invoice DROP operation_category');
        $this->addSql('ALTER TABLE invoice DROP vat_on_debits');
    }
}
