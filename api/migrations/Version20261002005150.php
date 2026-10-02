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
 * A customer's credit balance as entries (docs/SPEC.md § 7): money received that no invoice took, and credit paid into
 * an invoice. The balance is the sum of the amounts.
 */
final class Version20261002005150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'A customer\'s credit balance, as entries.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE customer_credit_entry (id UUID NOT NULL, entry_date DATE NOT NULL, kind VARCHAR(16) NOT NULL, amount NUMERIC(14, 3) NOT NULL, reference VARCHAR(64) DEFAULT NULL, notes TEXT DEFAULT NULL, invoice_id UUID DEFAULT NULL, payment_id UUID DEFAULT NULL, recorded_by UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, company_id UUID NOT NULL, customer_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_customer_credit_customer ON customer_credit_entry (customer_id)');
        $this->addSql('CREATE INDEX idx_customer_credit_company ON customer_credit_entry (company_id)');
        $this->addSql('CREATE INDEX idx_customer_credit_payment ON customer_credit_entry (payment_id)');
        $this->addSql('ALTER TABLE customer_credit_entry ADD CONSTRAINT FK_7D96D0C7979B1AD6 FOREIGN KEY (company_id) REFERENCES company (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE customer_credit_entry ADD CONSTRAINT FK_7D96D0C79395C3F3 FOREIGN KEY (customer_id) REFERENCES customer (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE customer_credit_entry DROP CONSTRAINT FK_7D96D0C7979B1AD6');
        $this->addSql('ALTER TABLE customer_credit_entry DROP CONSTRAINT FK_7D96D0C79395C3F3');
        $this->addSql('DROP TABLE customer_credit_entry');
    }
}
