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
 * Money kept to a customer's credit is now only what they paid beyond an invoice, a « trop-perçu » (docs/SPEC.md § 7,
 * row 208): an advance before any invoice takes a deposit invoice. The entries recorded before keep their amounts and
 * days under the new name; they named no invoice, and still do not.
 */
final class Version20261007104807 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Customer credit deposits become overpayments.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE customer_credit_entry SET kind = 'overpayment' WHERE kind = 'deposit'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE customer_credit_entry SET kind = 'deposit' WHERE kind = 'overpayment'");
    }
}
