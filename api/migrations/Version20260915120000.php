<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Payments recorded on issued invoices (docs/SPEC.md § 4 payment, § 7 2026-09-14), gone with their invoice. */
final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Invoice payments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment (id UUID NOT NULL, invoice_id UUID NOT NULL, payment_date DATE NOT NULL, amount NUMERIC(14, 3) NOT NULL, method VARCHAR(16) NOT NULL, reference VARCHAR(64) DEFAULT NULL, notes TEXT DEFAULT NULL, recorded_by UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_payment_invoice ON payment (invoice_id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE payment');
    }
}
