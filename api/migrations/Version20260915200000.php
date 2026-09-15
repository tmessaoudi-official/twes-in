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
 * The POC review's D11: the money and state rules of documents and expenses, held by PostgreSQL as well as by the
 * entities, so a write that reaches a table past its entity is refused there too. A credit note owes below zero by
 * design, so only an invoice's amount due is held at zero or above.
 */
final class Version20260915200000 extends AbstractMigration
{
    /** @var array<string, array<string, string>> table => check name => condition */
    private const array CHECKS = [
        'payment' => ['ck_payment_amount_positive' => 'amount > 0'],
        'invoice_line' => ['ck_invoice_line_quantity_positive' => 'quantity > 0'],
        'invoice' => [
            'ck_invoice_document_type' => "document_type IN ('invoice', 'credit_note')",
            'ck_invoice_status' => "status IN ('draft', 'issued', 'partially_paid', 'paid', 'cancelled')",
            'ck_invoice_credit_note_corrects' => "(document_type = 'credit_note') = (corrects_invoice_id IS NOT NULL)",
            'ck_invoice_amount_paid' => 'amount_paid >= 0',
            'ck_invoice_amount_credited' => 'amount_credited >= 0',
            'ck_invoice_amount_due' => "document_type <> 'invoice' OR amount_due IS NULL OR amount_due >= 0",
        ],
        'delivery_note' => ['ck_delivery_note_status' => "status IN ('draft', 'validated', 'delivered', 'cancelled', 'invoiced')"],
        'expense' => [
            'ck_expense_amount_net' => 'amount_net >= 0',
            'ck_expense_status' => "status IN ('draft', 'recorded', 'paid')",
        ],
    ];

    public function getDescription(): string
    {
        return 'Check constraints behind the money and state rules of documents and expenses.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::CHECKS as $table => $checks) {
            foreach ($checks as $name => $condition) {
                $this->addSql(\sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $name, $condition));
            }
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::CHECKS as $table => $checks) {
            foreach (array_keys($checks) as $name) {
                $this->addSql(\sprintf('ALTER TABLE %s DROP CONSTRAINT %s', $table, $name));
            }
        }
    }
}
