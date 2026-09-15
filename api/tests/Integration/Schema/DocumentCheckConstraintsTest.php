<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Schema;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The money and state rules of documents and expenses are the entities' first, and PostgreSQL's too: a write that
 * reaches the table past the entity (a stale copy, a script, a later migration) is refused there as well.
 */
final class DocumentCheckConstraintsTest extends KernelTestCase
{
    /** @return iterable<string, array{string, string, string}> the table, the check's name, a column the check reads */
    public static function checks(): iterable
    {
        yield 'a payment is above zero' => ['payment', 'ck_payment_amount_positive', 'amount'];
        yield 'an invoice line counts something' => ['invoice_line', 'ck_invoice_line_quantity_positive', 'quantity'];
        yield 'an invoice is an invoice or a credit note' => ['invoice', 'ck_invoice_document_type', 'document_type'];
        yield 'an invoice has a known status' => ['invoice', 'ck_invoice_status', 'status'];
        yield 'a credit note, and only a credit note, corrects an invoice' => ['invoice', 'ck_invoice_credit_note_corrects', 'corrects_invoice_id'];
        yield 'nothing is paid below zero' => ['invoice', 'ck_invoice_amount_paid', 'amount_paid'];
        yield 'nothing is credited below zero' => ['invoice', 'ck_invoice_amount_credited', 'amount_credited'];
        yield 'an invoice never owes below zero' => ['invoice', 'ck_invoice_amount_due', 'amount_due'];
        yield 'a delivery note has a known status' => ['delivery_note', 'ck_delivery_note_status', 'status'];
        yield 'an expense is not negative' => ['expense', 'ck_expense_amount_net', 'amount_net'];
        yield 'an expense has a known status' => ['expense', 'ck_expense_status', 'status'];
    }

    #[DataProvider('checks')]
    public function testTheDatabaseRefusesWhatTheEntitiesRefuse(string $table, string $name, string $column): void
    {
        self::bootKernel();
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        $definition = $connection->fetchOne(
            "SELECT pg_get_constraintdef(c.oid) FROM pg_constraint c WHERE c.contype = 'c' AND c.conrelid = CAST(? AS regclass) AND c.conname = ?",
            [$table, $name],
        );

        self::assertIsString($definition, \sprintf('The table %s carries no check %s.', $table, $name));
        self::assertStringContainsString($column, $definition);
    }
}
